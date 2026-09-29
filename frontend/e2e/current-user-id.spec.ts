// frontend/e2e/current-user-id.spec.ts
//
// useCurrentUserId(AuthorLink が使用)の回帰テスト。投稿一覧(/posts)で確認する。
//
// - currentUserId が表示に影響するのは敬称だけで、本人は「さん」なし、他人は「さん」付き。
//   リンク先は本人・他人とも /users/{id}。
// - バックエンド(http://localhost:8000)への通信はモックし、実バックエンド・DBは使わない。
//   モック対象外のバックエンド通信と、フロントエンド・バックエンド以外への通信は
//   遮断・記録し、テスト終了時に1件も無いことを確認する。
//   フロントエンド(baseURL)への通信は、Next.jsの資産・RSC・プリフェッチ等として許可する。
// - /api/me へのリクエストには、ページ内の初期化スクリプトで呼び出し順の番号
//   (X-E2E-Call-Index)を付ける。順序を制御するテストは、その番号で到着を待って保留し、
//   応答を返す順序を決める。順序が関係しないテストは、すべての /api/me に同じ応答を即座に返す
//   (meResponse)。
// - アプリが応答を処理し終えたかは、本文の読み取り(json() の完了・失敗)または
//   通信の失敗までを記録して確認する。その後の状態更新・描画の完了は直接は観測できないため、
//   「上書きされない」ことは、有限の観測時間(OBSERVATION_MS)のあいだ表示が維持される
//   ことで確認する。観測時間以降も変化しないことの保証ではない。

import { test as base, expect, type Page, type Route } from '@playwright/test'

const BACKEND_ORIGIN = 'http://localhost:8000'
const CALL_INDEX_HEADER = 'X-E2E-Call-Index'

const OBSERVATION_MS = 1_000
const OBSERVATION_INTERVAL_MS = 50

type MockResponse =
  | { kind: 'json'; status: number; body: unknown }
  | { kind: 'raw'; status: number; body: string }
  | { kind: 'network-error' }

type HeldMe = {
  callIndex: number
  released: boolean
  /** 応答を返し、アプリがその応答の本文を読み終える(または通信失敗を受け取る)まで待つ */
  release: (response: MockResponse) => Promise<void>
  /** 応答を返すだけ(後片付け用) */
  respond: (response: MockResponse) => void
}

type Api = {
  nextMe: (callIndex: number) => Promise<HeldMe>
  /** 保留中・保留済みの /api/me(順序を制御するモード) */
  meRequests: () => HeldMe[]
  /** ルートに到着した /api/me の件数(モードに関係なく数える) */
  meArrivals: () => number
  /** 到着した /api/me の Authorization ヘッダー */
  meAuthorizations: () => Array<string | null>
}

declare global {
  interface Window {
    __meE2E: {
      calls: () => number
      settledCount: () => number
      unhandledRejections: () => string[]
      whenSettled: (callIndex: number) => Promise<void>
    }
  }
}

// 実際のAPIと同じ応答形式
// - GET /api/posts: PostController::index(PostPresenter::format + meta)
// - GET /api/me: AuthController::me({ user: User })
const SELF = { id: 1, last_name: '山田', first_name: '太郎' }
const OTHER = { id: 2, last_name: '佐藤', first_name: null }

const author = (user: { id: number; last_name: string; first_name: string | null }) => ({
  ...user,
  age: null,
  region: null,
  trust_score: { total_score: 10, max_score: 50 },
  identity_verified: false,
})

const post = (id: number, user: typeof SELF | typeof OTHER) => ({
  id,
  user_id: user.id,
  category_id: 1,
  title: `投稿${id}`,
  view_count: 0,
  published_at: '2026-09-01T00:00:00.000000Z',
  created_at: '2026-09-01T00:00:00.000000Z',
  updated_at: '2026-09-01T00:00:00.000000Z',
  bookmark_count: 0,
  user: author(user),
  category: { id: 1, name: '政治', slug: 'politics' },
  tags: [],
})

const postsResponse = (posts: unknown[]) => ({
  posts,
  meta: { current_page: 1, last_page: 1, per_page: 20, total: posts.length },
})

const meOk = (user: { id: number; last_name: string; first_name: string | null }): MockResponse => ({
  kind: 'json',
  status: 200,
  body: {
    user: {
      ...user,
      email: `user${user.id}@example.com`,
      birthdate: '1990-01-01',
      email_verified_at: null,
      created_at: '2026-01-01T00:00:00.000000Z',
      updated_at: '2026-01-01T00:00:00.000000Z',
    },
  },
})

/**
 * ページの読み込み前(アプリのスクリプトより前)に実行する初期化スクリプト。
 * - token が文字列ならダミートークンを置き、null なら削除する。
 * - 未処理のPromise拒否(unhandledrejection)を記録する(pageerror と併せて確認する)。
 * - /api/me の呼び出しに番号を付け、本文の読み取り完了・失敗、または通信失敗を記録する。
 *   番号のヘッダーは、元のリクエスト(Authorization 等のヘッダー、メソッド等の設定)を
 *   複製したうえで追加する。記録できるのは本文の読み取りまでで、その後の React の
 *   状態反映の完了は記録しない。
 */
function E2E_INIT_SCRIPT({
  token,
  backendOrigin,
  callIndexHeader,
}: {
  token: string | null
  backendOrigin: string
  callIndexHeader: string
}) {
  if (token === null) {
    localStorage.removeItem('openpersona_token')
  } else {
    localStorage.setItem('openpersona_token', token)
  }

  const unhandledRejections: string[] = []
  window.addEventListener('unhandledrejection', (event) => {
    unhandledRejections.push(String(event.reason))
  })

  let calls = 0
  const settled = new Set<number>()
  const waiters = new Map<number, Array<() => void>>()

  const markSettled = (callIndex: number) => {
    settled.add(callIndex)
    for (const resolve of waiters.get(callIndex) ?? []) {
      resolve()
    }
    waiters.delete(callIndex)
  }

  const originalFetch = window.fetch.bind(window)

  window.fetch = (input, init) => {
    const url = new URL(
      input instanceof Request ? input.url : String(input),
      window.location.href,
    )

    if (url.origin !== backendOrigin || url.pathname !== '/api/me') {
      return originalFetch(input, init)
    }

    calls += 1
    const callIndex = calls
    const request = new Request(input, init)
    request.headers.set(callIndexHeader, String(callIndex))

    return originalFetch(request).then(
      (response) => {
        const originalJson = response.json.bind(response)
        response.json = () =>
          originalJson().then(
            (data) => {
              markSettled(callIndex)
              return data
            },
            (error) => {
              markSettled(callIndex)
              throw error
            },
          )
        return response
      },
      (error) => {
        markSettled(callIndex)
        throw error
      },
    )
  }

  window.__meE2E = {
    calls: () => calls,
    settledCount: () => settled.size,
    unhandledRejections: () => [...unhandledRejections],
    whenSettled: (callIndex) =>
      settled.has(callIndex)
        ? Promise.resolve()
        : new Promise<void>((resolve) => {
            waiters.set(callIndex, [...(waiters.get(callIndex) ?? []), resolve])
          }),
  }
}

const test = base.extend<{
  token: string | null
  posts: unknown[]
  /** 指定すると、すべての /api/me にこの応答を即座に返す。null なら保留して順序を制御する */
  meResponse: MockResponse | null
  api: Api
}>({
  token: [null, { option: true }],
  posts: [[post(10, SELF), post(20, OTHER)], { option: true }],
  meResponse: [null, { option: true }],
  // すべてのテストでモック・通信遮断を有効にするため、テストが参照しなくても自動で実行する。
  // 第2引数は慣例では use だが、ReactのHook(use)と誤認されないよう別名にする
  api: [async ({ page, baseURL, token, posts, meResponse }, provide) => {
    if (!baseURL) {
      throw new Error('playwright.config.ts の baseURL が未設定です')
    }

    const frontendOrigin = new URL(baseURL).origin
    const corsHeaders = {
      'Access-Control-Allow-Origin': frontendOrigin,
      'Access-Control-Allow-Methods': 'GET, OPTIONS',
      'Access-Control-Allow-Headers': `Accept, Authorization, ${CALL_INDEX_HEADER}`,
    }
    const unexpected: string[] = []
    const held: HeldMe[] = []
    const meAuthorizations: Array<string | null> = []
    const waiters = new Map<number, (item: HeldMe) => void>()

    await page.route('**/*', async (route) => {
      const url = new URL(route.request().url())

      if (url.origin === frontendOrigin && !url.pathname.startsWith('/api/')) {
        await route.fallback()
        return
      }

      unexpected.push(`${route.request().method()} ${url.href}`)
      await route.abort()
    })

    const fulfillJson = (route: Route, body: unknown) =>
      route.fulfill({
        status: 200,
        headers: corsHeaders,
        contentType: 'application/json',
        body: JSON.stringify(body),
      })

    await page.route(
      (url) => url.origin === BACKEND_ORIGIN,
      async (route) => {
        const request = route.request()
        const url = new URL(request.url())
        const method = request.method()

        if (method === 'OPTIONS' && url.pathname === '/api/me') {
          await route.fulfill({ status: 204, headers: corsHeaders })
          return
        }

        if (method === 'GET' && url.pathname === '/api/posts') {
          await fulfillJson(route, postsResponse(posts))
          return
        }

        // アンマウントのケースで移動する /timeline の取得(FollowController::timeline)
        if (method === 'GET' && url.pathname === '/api/timeline') {
          await fulfillJson(route, { posts: [] })
          return
        }

        if (method === 'GET' && url.pathname === '/api/me') {
          const callIndex = Number(
            (await request.headerValue(CALL_INDEX_HEADER)) ?? Number.NaN,
          )
          meAuthorizations.push(await request.headerValue('Authorization'))
          let resolveResponse!: (response: MockResponse) => void
          const responsePromise = new Promise<MockResponse>((resolve) => {
            resolveResponse = resolve
          })

          const item: HeldMe = {
            callIndex,
            released: false,
            respond: (response) => {
              item.released = true
              resolveResponse(response)
            },
            release: async (response) => {
              item.respond(response)
              await page.evaluate(
                (index) => window.__meE2E.whenSettled(index),
                callIndex,
              )
            },
          }

          if (meResponse) {
            item.respond(meResponse)
          } else {
            held.push(item)
            waiters.get(callIndex)?.(item)
            waiters.delete(callIndex)
          }

          const response = await responsePromise

          if (response.kind === 'network-error') {
            await route.abort('failed')
            return
          }

          await route.fulfill({
            status: response.status,
            headers: corsHeaders,
            contentType: 'application/json',
            body:
              response.kind === 'json'
                ? JSON.stringify(response.body)
                : response.body,
          })
          return
        }

        unexpected.push(`${method} ${url.href}`)
        await route.abort()
      },
    )

    await page.addInitScript(E2E_INIT_SCRIPT, {
      token,
      backendOrigin: BACKEND_ORIGIN,
      callIndexHeader: CALL_INDEX_HEADER,
    })

    await provide({
      nextMe: (callIndex) => {
        const found = held.find((item) => item.callIndex === callIndex)

        if (found) {
          return Promise.resolve(found)
        }

        return new Promise<HeldMe>((resolve) => {
          waiters.set(callIndex, resolve)
        })
      },
      meRequests: () => [...held],
      meArrivals: () => meAuthorizations.length,
      meAuthorizations: () => [...meAuthorizations],
    })

    for (const item of held) {
      if (!item.released) {
        item.respond({ kind: 'network-error' })
      }
    }

    expect(unexpected, '想定外の通信がありました').toEqual([])
  }, { auto: true }],
})

/** コンソールのエラーと pageerror を集める(未処理のPromise拒否は初期化スクリプトで別途記録) */
function collectErrors(page: Page) {
  const consoleErrors: string[] = []
  const pageErrors: string[] = []

  page.on('console', (message) => {
    if (message.type() === 'error') {
      consoleErrors.push(message.text())
    }
  })

  page.on('pageerror', (error) => {
    pageErrors.push(error.message)
  })

  return { consoleErrors, pageErrors }
}

/**
 * check が OBSERVATION_MS のあいだ成り立ち続けることを確認する。
 * check の中では再試行付きアサーションを使わず、その時点の値を1回だけ読んで判定する。
 * 有限時間の観測であり、それ以降も変化しないことを保証するものではない。
 */
async function expectStable(page: Page, check: () => Promise<void>) {
  const deadline = Date.now() + OBSERVATION_MS

  while (Date.now() < deadline) {
    await check()
    await page.waitForTimeout(OBSERVATION_INTERVAL_MS)
  }

  await check()
}

const authorLink = (page: Page, userId: number) =>
  page.locator(`a[href="/users/${userId}"]`)

/** 本人表示(敬称なし)。表示は「姓名 · 透明性 10/50」 */
const SELF_LABEL = /^山田太郎 · /
/** 他人表示(敬称あり) */
const SELF_AS_OTHER_LABEL = /^山田太郎さん · /
const OTHER_LABEL = /^佐藤さん · /

async function openPosts(page: Page) {
  await page.goto('/posts')
  await expect(page.getByRole('heading', { level: 1, name: '投稿一覧' })).toBeVisible()
  await expect(authorLink(page, SELF.id)).toBeVisible()
  await expect(authorLink(page, OTHER.id)).toBeVisible()
}

test.describe('トークンなし', () => {
  test.use({ token: null })

  test('/api/me を呼ばず、全員に敬称が付く', async ({ page, api }) => {
    const errors = collectErrors(page)

    await openPosts(page)
    await expect(authorLink(page, SELF.id)).toHaveText(SELF_AS_OTHER_LABEL)
    await expect(authorLink(page, OTHER.id)).toHaveText(OTHER_LABEL)

    await expectStable(page, async () => {
      expect(await page.evaluate(() => window.__meE2E.calls())).toBe(0)
      expect(api.meArrivals()).toBe(0)
    })

    expect(errors.pageErrors).toEqual([])
    expect(await unhandledRejections(page)).toEqual([])
  })
})

/**
 * 即座に応答するモード(meResponse)で、/api/me が実際に呼ばれ(ルートに1件以上到着し)、
 * アプリがその応答をすべて読み終える(本文の読み取り完了・失敗、または通信失敗)まで待つ。
 * 0件同士の一致では成功しない。React の状態反映の完了はここでは待たない。
 */
async function waitForAllMeSettled(page: Page, api: Api) {
  await expect.poll(() => api.meArrivals()).toBeGreaterThan(0)
  await expect
    .poll(() =>
      page.evaluate(() => {
        const calls = window.__meE2E.calls()
        return calls > 0 && window.__meE2E.settledCount() === calls
      }),
    )
    .toBe(true)
}

async function unhandledRejections(page: Page) {
  return page.evaluate(() => window.__meE2E.unhandledRejections())
}

test.describe('ダミートークンあり・正常な応答', () => {
  test.use({ token: 'e2e-dummy-token', meResponse: meOk(SELF) })

  test('本人だけ敬称なしになり、リンク先は本人・他人とも /users/{id}', async ({ page, api }) => {
    const errors = collectErrors(page)

    await openPosts(page)
    await waitForAllMeSettled(page, api)
    // 元の Authorization ヘッダーが維持されている
    expect(new Set(api.meAuthorizations())).toEqual(new Set(['Bearer e2e-dummy-token']))

    await expect(authorLink(page, SELF.id)).toHaveText(SELF_LABEL)
    await expect(authorLink(page, OTHER.id)).toHaveText(OTHER_LABEL)
    await expect(authorLink(page, SELF.id)).toHaveAttribute('href', `/users/${SELF.id}`)
    await expect(authorLink(page, OTHER.id)).toHaveAttribute('href', `/users/${OTHER.id}`)

    expect(errors.pageErrors).toEqual([])
    expect(await unhandledRejections(page)).toEqual([])
  })
})

const failures: Array<[string, MockResponse]> = [
  ['401', { kind: 'json', status: 401, body: { message: 'Unauthenticated.' } }],
  ['500', { kind: 'json', status: 500, body: { message: 'Server Error' } }],
  ['通信失敗', { kind: 'network-error' }],
  ['不正なJSON', { kind: 'raw', status: 200, body: '<html>not json</html>' }],
]

for (const [label, response] of failures) {
  test.describe(`ダミートークンあり・${label}`, () => {
    test.use({ token: 'e2e-dummy-token', meResponse: response })

    test('全員に敬称が付き、未処理の例外・Promise拒否が出ない', async ({ page, api }) => {
      const errors = collectErrors(page)

      await openPosts(page)
      await waitForAllMeSettled(page, api)

      await expectStable(page, async () => {
        expect(await authorLink(page, SELF.id).textContent()).toMatch(
          SELF_AS_OTHER_LABEL,
        )
        expect(await authorLink(page, OTHER.id).textContent()).toMatch(OTHER_LABEL)
        expect(await unhandledRejections(page)).toEqual([])
      })

      expect(errors.pageErrors).toEqual([])
    })
  })
}

test.describe('cleanup 後に届いた応答', () => {
  test.use({ token: 'e2e-dummy-token', posts: [post(10, SELF)] })

  test('Strict Mode で cleanup 済みの取得の応答は、最新の取得の結果を上書きしない', async ({
    page,
    api,
  }) => {
    // 開発時の Strict Mode では effect の setup → cleanup → setup が行われ、
    // AuthorLink 1件につき /api/me が2回呼ばれる。1回目は cleanup 済み。
    // cleanup の動作を観測するため、同じトークンに対して1回目だけ別の利用者を返す
    // 人工的な応答を使う(実際のAPIがこう応答することを想定したものではない)。
    const errors = collectErrors(page)

    await openSinglePostList(page)
    // 2回の通信が別々の AuthorLink 由来にならないよう、投稿者リンクが1つだけであることを確認する
    await expect(page.locator('a[href^="/users/"]')).toHaveCount(1)
    const cleanedUp = await api.nextMe(1)
    const latest = await api.nextMe(2)

    // 最新の取得を先に完了させ、本人表示になることを確認する
    await latest.release(meOk(SELF))
    await expect(authorLink(page, SELF.id)).toHaveText(SELF_LABEL)

    // cleanup 済みの取得を後から完了させる。反映されれば他人扱い(敬称付き)に変わる
    await cleanedUp.release(meOk(OTHER))
    await expectStable(page, async () => {
      expect(await authorLink(page, SELF.id).textContent()).toMatch(SELF_LABEL)
    })

    expect(await page.evaluate(() => window.__meE2E.calls())).toBe(2)
    expect(api.meArrivals()).toBe(2)
    expect(errors.pageErrors).toEqual([])
    expect(await unhandledRejections(page)).toEqual([])
  })

  test('アンマウント後に届いた応答でエラーが出ない', async ({ page, api }) => {
    // エラーが出ないことは確認できるが、アンマウント後の状態更新は修正前でも
    // 画面に影響しないため、このテストだけでは更新が抑止されたことは証明できない。
    // また、投稿者リンクがDOMから無くなったことは、React内部でアンマウントされたことの
    // 証明ではない(遷移先の画面が表示されたことの確認にとどまる)。
    const errors = collectErrors(page)

    await openSinglePostList(page)
    // 少なくとも1件の取得が保留中であることを確認してから移動する
    await api.nextMe(1)

    // 投稿一覧の既存リンクからタイムラインへ移動する(遷移先の /api/timeline もモック済み)
    await page.getByRole('link', { name: 'タイムライン', exact: true }).click()
    await expect(page).toHaveURL(/\/timeline$/)
    await expect(page.getByRole('heading', { level: 1, name: 'タイムライン' })).toBeVisible()
    await expect(authorLink(page, SELF.id)).toHaveCount(0)

    // 移動前に始まり保留中だった取得をすべて完了させる
    for (const request of api.meRequests().filter((item) => !item.released)) {
      await request.release(meOk(SELF))
    }

    await expectStable(page, async () => {
      expect(new URL(page.url()).pathname).toBe('/timeline')
      expect(errors.pageErrors).toEqual([])
      expect(errors.consoleErrors).toEqual([])
      expect(await unhandledRejections(page)).toEqual([])
    })
  })
})

/** 投稿1件(本人)の一覧を開く */
async function openSinglePostList(page: Page) {
  await page.goto('/posts')
  await expect(page.getByRole('heading', { level: 1, name: '投稿一覧' })).toBeVisible()
  await expect(authorLink(page, SELF.id)).toBeVisible()
}
