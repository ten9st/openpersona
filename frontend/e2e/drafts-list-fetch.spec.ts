// frontend/e2e/drafts-list-fetch.spec.ts
//
// 下書き一覧(/posts/drafts)の取得処理の回帰テスト。
//
// - 下書き一覧はトークンの「存在」だけを見て取得する。ダミーのトークンを localStorage に
//   置き、GET /api/posts/drafts の応答はモックする。実バックエンド・DBは使わない。
// - モック対象外のバックエンド通信と、フロントエンド・バックエンド以外への通信は
//   遮断・記録し、テスト終了時に1件も無いことを確認する。
//   フロントエンド(baseURL)への通信は、Next.jsの資産・RSC・プリフェッチ等として許可する。
// - /api/posts/drafts へのリクエストには、ページ内の初期化スクリプトで呼び出し順の番号
//   (X-E2E-Call-Index)を付け、ルートで保留する。テストが応答を決めるまで返さない。
// - 応答を返した後は、アプリが本文を読み終えた(json() の完了・失敗)こと、または
//   通信失敗を受け取ったことまでを記録して待つ。これは React の状態反映・描画の完了ではない。
//   表示されることは再試行付きアサーションで確認し、表示が維持されること(上書きされない
//   こと)は、有限の観測時間(OBSERVATION_MS)のあいだ変化しないことで確認する。
//   観測時間以降も変化しないことの保証ではない。
// - 「下書きはありません。」がDOMへ追加されたことを、ページ読み込み前に設置した
//   MutationObserver で数える。サーバー描画のHTMLの解析による追加も数えるため、
//   取得前(ハイドレーション前後を含む)に空一覧のメッセージが出ていないことを確認できる。
//   画面への描画(ペイント)は確認しない。
// - 未処理のPromise拒否は、ページのスクリプトより前に設置した unhandledrejection の
//   記録と pageerror で確認する。
//
// 実行段階での確認予定: ページの ignore による抑止を無効にした改変版で、
// 後処理済みの応答のテストが失敗すること(検出力)を確認する。

import { test as base, expect, type Locator, type Page, type Route } from '@playwright/test'

const BACKEND_ORIGIN = 'http://localhost:8000'
const DRAFTS_PATH = '/api/posts/drafts'
const CALL_INDEX_HEADER = 'X-E2E-Call-Index'
const TOKEN_KEY = 'openpersona_token'
const DUMMY_TOKEN = 'e2e-dummy-token'

const EMPTY_MESSAGE = '下書きはありません。'
const LOADING_MESSAGE = '読み込み中...'
const ERROR_MESSAGE = '下書き一覧の取得に失敗しました。'

const OBSERVATION_MS = 1_000
const OBSERVATION_INTERVAL_MS = 50

type MockResponse =
  | { kind: 'json'; status: number; body: unknown }
  | { kind: 'raw'; status: number; body: string }
  | { kind: 'network-error' }

type HeldDrafts = {
  callIndex: number
  authorization: string | null
  released: boolean
  /** 応答を返す(本文の読み取りは待たない) */
  respond: (response: MockResponse) => void
}

type Api = {
  /** 到着済みの /api/posts/drafts(呼び出し順) */
  arrived: () => HeldDrafts[]
  /** 到着済みの件数が count 件以上になるまで待つ(届かなければテストのタイムアウトで失敗する) */
  waitForArrivals: (count: number) => Promise<HeldDrafts[]>
  /** 応答を返し、アプリが本文を読み終える(または通信失敗を受け取る)まで待つ */
  release: (item: HeldDrafts, response: MockResponse) => Promise<void>
}

declare global {
  interface Window {
    __draftsE2E: {
      /** ページ内で始まった /api/posts/drafts の呼び出し件数 */
      calls: () => number
      whenSettled: (callIndex: number) => Promise<void>
      unhandledRejections: () => string[]
      /** 「下書きはありません。」がDOMに追加された回数 */
      emptyInsertions: () => number
    }
  }
}

// 実際のAPI(PostController::drafts: PostQueryService::draftsForUser)と同じ応答形式
const draft = (id: number, title: string) => ({
  id,
  category_id: 1,
  title,
  status: 'draft',
  created_at: '2026-09-01T00:00:00.000000Z',
  updated_at: '2026-09-02T00:00:00.000000Z',
  category: { id: 1, name: '政治', slug: 'politics' },
})

type MockDraft = ReturnType<typeof draft>

const draftsOk = (posts: MockDraft[]): MockResponse => ({
  kind: 'json',
  status: 200,
  body: { posts },
})

// 実際のAPI(PostController::index)と同じ応答形式。画面離脱先の投稿一覧用
const EMPTY_POSTS_RESPONSE = {
  posts: [],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
}

/**
 * ページの読み込み前(アプリのスクリプトより前)に実行する初期化スクリプト。
 * - token が文字列ならダミートークンを置き、null なら削除する
 *   (文書の読み込みごとに実行されるため、テスト側で読み込みが1回だけであることを確認する)。
 * - 未処理のPromise拒否(unhandledrejection)を記録する。
 * - /api/posts/drafts の呼び出しを同期的に記録して番号を付け、本文の読み取り完了・失敗、
 *   または通信失敗を記録する。番号のヘッダーは、元のリクエスト(ヘッダー・メソッド等の
 *   設定)を複製したうえで追加する。React の状態反映の完了は記録しない。
 * - 空一覧のメッセージを含むノードの追加(またはテキストの変更)を数える。
 */
function E2E_INIT_SCRIPT({
  token,
  tokenKey,
  backendOrigin,
  draftsPath,
  callIndexHeader,
  emptyMessage,
}: {
  token: string | null
  tokenKey: string
  backendOrigin: string
  draftsPath: string
  callIndexHeader: string
  emptyMessage: string
}) {
  if (token === null) {
    localStorage.removeItem(tokenKey)
  } else {
    localStorage.setItem(tokenKey, token)
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

    if (url.origin !== backendOrigin || url.pathname !== draftsPath) {
      return originalFetch(input, init)
    }

    calls += 1
    const index = calls
    const request = new Request(input, init)
    request.headers.set(callIndexHeader, String(index))

    return originalFetch(request).then(
      (response) => {
        const originalJson = response.json.bind(response)
        response.json = () =>
          originalJson().then(
            (data) => {
              markSettled(index)
              return data
            },
            (error) => {
              markSettled(index)
              throw error
            },
          )
        return response
      },
      (error) => {
        markSettled(index)
        throw error
      },
    )
  }

  let emptyInsertions = 0
  const containsEmpty = (node: Node) => (node.textContent ?? '').includes(emptyMessage)

  new MutationObserver((records) => {
    for (const record of records) {
      if (record.type === 'characterData' && containsEmpty(record.target)) {
        emptyInsertions += 1
      }

      for (const node of Array.from(record.addedNodes)) {
        if (containsEmpty(node)) {
          emptyInsertions += 1
        }
      }
    }
  }).observe(document, { childList: true, subtree: true, characterData: true })

  window.__draftsE2E = {
    calls: () => calls,
    whenSettled: (callIndex) =>
      settled.has(callIndex)
        ? Promise.resolve()
        : new Promise<void>((resolve) => {
            waiters.set(callIndex, [...(waiters.get(callIndex) ?? []), resolve])
          }),
    unhandledRejections: () => [...unhandledRejections],
    emptyInsertions: () => emptyInsertions,
  }
}

const test = base.extend<{ token: string | null; api: Api }>({
  token: [DUMMY_TOKEN, { option: true }],
  // すべてのテストでモック・通信遮断を有効にするため、テストが参照しなくても自動で実行する。
  // 第2引数は慣例では use だが、ReactのHook(use)と誤認されないよう別名にする
  api: [
    async ({ page, baseURL, token }, provide) => {
      if (!baseURL) {
        throw new Error('playwright.config.ts の baseURL が未設定です')
      }

      const frontendOrigin = new URL(baseURL).origin
      const corsHeaders = { 'Access-Control-Allow-Origin': frontendOrigin }
      const unexpected: string[] = []
      const held: HeldDrafts[] = []
      const arrivalWaiters: Array<() => void> = []

      // フロントエンド・バックエンド以外への通信は遮断して記録する
      // (後から登録したrouteが優先されるため、バックエンド用はこの後に登録する)
      await page.route('**/*', async (route) => {
        const url = new URL(route.request().url())

        if (url.origin === frontendOrigin && !url.pathname.startsWith('/api/')) {
          await route.fallback()
          return
        }

        unexpected.push(`${route.request().method()} ${url.href}`)
        await route.abort()
      })

      const fulfill = (route: Route, response: MockResponse) => {
        if (response.kind === 'network-error') {
          return route.abort('failed')
        }

        return route.fulfill({
          status: response.status,
          headers: corsHeaders,
          contentType: 'application/json',
          body:
            response.kind === 'json' ? JSON.stringify(response.body) : response.body,
        })
      }

      await page.route(
        (url) => url.origin === BACKEND_ORIGIN,
        async (route) => {
          const request = route.request()
          const url = new URL(request.url())
          const method = request.method()

          if (method === 'GET' && url.pathname === DRAFTS_PATH) {
            const callIndex = Number(
              (await request.headerValue(CALL_INDEX_HEADER)) ?? Number.NaN,
            )
            let resolveResponse!: (response: MockResponse) => void
            const responsePromise = new Promise<MockResponse>((resolve) => {
              resolveResponse = resolve
            })

            const item: HeldDrafts = {
              callIndex,
              authorization: await request.headerValue('Authorization'),
              released: false,
              respond: (response) => {
                if (item.released) {
                  return
                }
                item.released = true
                resolveResponse(response)
              },
            }

            held.push(item)
            held.sort((x, y) => x.callIndex - y.callIndex)
            for (const notify of arrivalWaiters.splice(0)) {
              notify()
            }

            await fulfill(route, await responsePromise)
            return
          }

          // 画面離脱先の投稿一覧。空一覧のため、投稿者の敬称用の /api/me は呼ばれない
          if (method === 'GET' && url.pathname === '/api/posts') {
            await route.fulfill({
              status: 200,
              headers: corsHeaders,
              contentType: 'application/json',
              body: JSON.stringify(EMPTY_POSTS_RESPONSE),
            })
            return
          }

          unexpected.push(`${method} ${url.href}`)
          await route.abort()
        },
      )

      await page.addInitScript(E2E_INIT_SCRIPT, {
        token,
        tokenKey: TOKEN_KEY,
        backendOrigin: BACKEND_ORIGIN,
        draftsPath: DRAFTS_PATH,
        callIndexHeader: CALL_INDEX_HEADER,
        emptyMessage: EMPTY_MESSAGE,
      })

      await provide({
        arrived: () => [...held],
        waitForArrivals: async (count) => {
          while (held.length < count) {
            await new Promise<void>((resolve) => arrivalWaiters.push(resolve))
          }
          return [...held]
        },
        release: async (item, response) => {
          item.respond(response)
          await page.evaluate(
            (index) => window.__draftsE2E.whenSettled(index),
            item.callIndex,
          )
        },
      })

      for (const item of held) {
        item.respond({ kind: 'network-error' })
      }

      expect(unexpected, '想定外の通信がありました').toEqual([])
    },
    { auto: true },
  ],
})

/** 文書の読み込み(ページ全体の読み込み)の回数を数える */
function countDocumentLoads(page: Page) {
  let loads = 0
  page.on('domcontentloaded', () => {
    loads += 1
  })
  return () => loads
}

function collectPageErrors(page: Page) {
  const pageErrors: string[] = []
  page.on('pageerror', (error) => {
    pageErrors.push(error.message)
  })
  return pageErrors
}

async function unhandledRejections(page: Page) {
  return page.evaluate(() => window.__draftsE2E.unhandledRejections())
}

async function pageCalls(page: Page) {
  return page.evaluate(() => window.__draftsE2E.calls())
}

async function emptyInsertions(page: Page) {
  return page.evaluate(() => window.__draftsE2E.emptyInsertions())
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

async function countNow(locator: Locator) {
  return locator.count()
}

const heading = (page: Page) =>
  page.getByRole('heading', { level: 1, name: '下書き一覧' })
const draftTitle = (page: Page, title: string) =>
  page.getByRole('heading', { level: 2, name: title, exact: true })
const anyDraftTitle = (page: Page) => page.getByRole('heading', { level: 2 })
const loadingStatus = (page: Page) =>
  page.getByRole('status').filter({ hasText: LOADING_MESSAGE })
const errorStatus = (page: Page) =>
  page.getByRole('status').filter({ hasText: ERROR_MESSAGE })
const emptyMessage = (page: Page) => page.getByText(EMPTY_MESSAGE, { exact: true })
/**
 * 各カードの「編集する」「詳細を見る」リンク(視覚的に隠した「：タイトル」を名前に含む)。
 * Chromium で計算されるアクセシブルな名前は「編集する ：下書き1」のように、隠した部分の
 * 前に空白が入るため、空白の有無を問わず名前全体で照合する。
 */
const draftLink = (page: Page, action: string, title: string) =>
  page.getByRole('link', {
    name: new RegExp(`^${action}\\s*：${title}$`),
  })

/** 読み込み中は、読み込み表示だけで、一覧・空一覧・エラーを表示していない */
async function expectLoadingOnlyNow(page: Page) {
  expect(await countNow(loadingStatus(page))).toBe(1)
  expect(await countNow(anyDraftTitle(page))).toBe(0)
  expect(await countNow(emptyMessage(page))).toBe(0)
  expect(await countNow(errorStatus(page))).toBe(0)
}

/** 下書き一覧を直接開き、最初の取得が到着するまで待つ */
async function openDrafts(page: Page, api: Api) {
  await page.goto('/posts/drafts')
  await expect(heading(page)).toBeVisible()
  const [first] = await api.waitForArrivals(1)
  return first
}

/** 到着済みで未応答の取得すべてに同じ応答を返す */
async function releaseAll(api: Api, response: MockResponse) {
  for (const item of api.arrived().filter((held) => !held.released)) {
    await api.release(item, response)
  }
}

let documentLoads: () => number
let pageErrors: string[]

test.beforeEach(({ page }) => {
  documentLoads = countDocumentLoads(page)
  pageErrors = collectPageErrors(page)
})

test.afterEach(async ({ page }) => {
  // 同じ文書内で操作が行われ、未処理の例外・Promise拒否が無い
  expect(documentLoads()).toBe(1)
  expect(pageErrors).toEqual([])
  expect(await unhandledRejections(page)).toEqual([])
})

test.describe('表示', () => {
  test('取得前は読み込み表示だけで、空一覧のメッセージを出さない', async ({ page, api }) => {
    await openDrafts(page, api)

    await expect(loadingStatus(page)).toBeVisible()
    await expectStable(page, () => expectLoadingOnlyNow(page))
    // サーバー描画・ハイドレーションを含め、取得前に空一覧のメッセージがDOMへ追加されていない
    expect(await emptyInsertions(page)).toBe(0)

    // トークンは従来どおり Authorization ヘッダーで送る
    expect(api.arrived()[0].authorization).toBe(`Bearer ${DUMMY_TOKEN}`)

    await releaseAll(api, draftsOk([draft(1, '下書き1')]))
    await expect(draftTitle(page, '下書き1')).toBeVisible()
    await expect(loadingStatus(page)).toHaveCount(0)
  })

  test('一覧を表示し、編集・詳細へのリンクを表示する', async ({ page, api }) => {
    await openDrafts(page, api)
    await releaseAll(api, draftsOk([draft(1, '下書き1'), draft(2, '下書き2')]))

    await expect(draftTitle(page, '下書き1')).toBeVisible()
    await expect(draftTitle(page, '下書き2')).toBeVisible()
    await expect(draftLink(page, '編集する', '下書き1')).toHaveAttribute('href', '/posts/1/edit')
    await expect(draftLink(page, '詳細を見る', '下書き1')).toHaveAttribute('href', '/posts/1')
    await expect(draftLink(page, '編集する', '下書き2')).toHaveAttribute('href', '/posts/2/edit')
    await expect(draftLink(page, '詳細を見る', '下書き2')).toHaveAttribute('href', '/posts/2')
    await expect(page.getByRole('link', { name: '新規作成', exact: true })).toHaveAttribute(
      'href',
      '/posts/create',
    )
    await expect(emptyMessage(page)).toHaveCount(0)
    await expect(errorStatus(page)).toHaveCount(0)
    await expect(loadingStatus(page)).toHaveCount(0)
  })

  test('空一覧では、空一覧のメッセージだけを表示する', async ({ page, api }) => {
    await openDrafts(page, api)
    await releaseAll(api, draftsOk([]))

    await expect(emptyMessage(page)).toBeVisible()
    await expect(loadingStatus(page)).toHaveCount(0)
    await expect(errorStatus(page)).toHaveCount(0)
  })
})

test.describe('エラー', () => {
  const failures: Array<[string, MockResponse]> = [
    ['401', { kind: 'json', status: 401, body: { message: 'Unauthenticated.' } }],
    ['500', { kind: 'json', status: 500, body: { message: 'Server Error' } }],
    ['通信失敗', { kind: 'network-error' }],
    ['不正なJSON', { kind: 'raw', status: 200, body: '<html>not json</html>' }],
  ]

  for (const [label, response] of failures) {
    test(`${label}の場合はエラーを表示し、読み込み表示・空一覧を残さない`, async ({
      page,
      api,
    }) => {
      await openDrafts(page, api)
      await releaseAll(api, response)

      await expect(errorStatus(page)).toBeVisible()
      await expectStable(page, async () => {
        expect(await countNow(errorStatus(page))).toBe(1)
        expect(await countNow(loadingStatus(page))).toBe(0)
        expect(await countNow(emptyMessage(page))).toBe(0)
        expect(await countNow(anyDraftTitle(page))).toBe(0)
        // 401 でもログイン画面へは移動しない(従来どおり)
        expect(new URL(page.url()).pathname).toBe('/posts/drafts')
      })
    })
  }
})

test.describe('トークンなし', () => {
  test.use({ token: null })

  test('下書きを取得せずに /login へ移動し、空一覧のメッセージを出さない', async ({
    page,
  }) => {
    await page.goto('/posts/drafts')

    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByRole('heading', { level: 1, name: 'ログイン' })).toBeVisible()

    expect(await pageCalls(page)).toBe(0)
    expect(await emptyInsertions(page)).toBe(0)
  })
})

test.describe('後処理済みの応答', () => {
  // 開発時の Strict Mode では、クライアント側で新しくマウントされたページの effect が
  // setup → cleanup → setup と実行され、取得が2回始まりうる。投稿一覧の操作欄の
  // 「下書き一覧」リンクからのクライアント側の移動で観測する(直接アクセスのハイドレーション
  // では1回だけの可能性がある)。2件目が届かない場合は waitForArrivals がタイムアウトし、
  // このテストの前提(2件の取得)を満たさない失敗になる。黙って成功・skipにはしない。
  // 呼び出し順の1件目を cleanup 済み、2件目を最新の取得とみなす(実行段階で経路を確認する)。
  // cleanup の動作を観測するため、1件目にだけ別の結果を返す人工的な応答を使う。

  /** 投稿一覧からクライアント側で下書き一覧へ移動し、2件の取得の到着を待つ */
  async function moveToDraftsFromPosts(page: Page, api: Api) {
    await page.goto('/posts')
    await expect(page.getByRole('heading', { level: 1, name: '投稿一覧' })).toBeVisible()
    await page.getByRole('link', { name: '下書き一覧', exact: true }).click()
    await expect(page).toHaveURL(/\/posts\/drafts$/)
    await expect(heading(page)).toBeVisible()

    const [cleanedUp, latest] = await api.waitForArrivals(2)
    return { cleanedUp, latest }
  }

  test('後処理済みの取得の成功は、最新の取得の結果を上書きしない', async ({ page, api }) => {
    const { cleanedUp, latest } = await moveToDraftsFromPosts(page, api)

    await api.release(latest, draftsOk([draft(10, '最新の取得の下書き')]))
    await expect(draftTitle(page, '最新の取得の下書き')).toBeVisible()

    await api.release(cleanedUp, draftsOk([draft(11, '後処理済みの取得の下書き')]))
    await expectStable(page, async () => {
      expect(await countNow(draftTitle(page, '最新の取得の下書き'))).toBe(1)
      expect(await countNow(draftTitle(page, '後処理済みの取得の下書き'))).toBe(0)
      // 3件目以降の取得は始まっていない
      expect(api.arrived()).toHaveLength(2)
    })
  })

  test('後処理済みの取得の失敗は、最新の取得の結果にエラーを出さない', async ({
    page,
    api,
  }) => {
    const { cleanedUp, latest } = await moveToDraftsFromPosts(page, api)

    await api.release(latest, draftsOk([draft(10, '最新の取得の下書き')]))
    await expect(draftTitle(page, '最新の取得の下書き')).toBeVisible()

    await api.release(cleanedUp, { kind: 'network-error' })
    await expectStable(page, async () => {
      expect(await countNow(draftTitle(page, '最新の取得の下書き'))).toBe(1)
      expect(await countNow(errorStatus(page))).toBe(0)
      expect(api.arrived()).toHaveLength(2)
    })
  })

  test('最新の取得の応答より前に後処理済みの取得の応答が届いても、読み込み表示のまま', async ({
    page,
    api,
  }) => {
    const { cleanedUp, latest } = await moveToDraftsFromPosts(page, api)

    await api.release(cleanedUp, draftsOk([draft(11, '後処理済みの取得の下書き')]))
    await expectStable(page, () => expectLoadingOnlyNow(page))

    await api.release(latest, draftsOk([draft(10, '最新の取得の下書き')]))
    await expect(draftTitle(page, '最新の取得の下書き')).toBeVisible()
  })
})

test.describe('画面離脱', () => {
  test('取得中に公開済み一覧へ移動した後に応答が届いても、エラーが出ない', async ({
    page,
    api,
  }) => {
    // エラーが出ないこと・移動先の表示が変わらないことは確認できるが、離脱後の状態更新は
    // ignore が無くても画面に影響しないため、このテストだけでは状態更新が抑止されたことは
    // 証明できない。また、下書き一覧の見出しがDOMから無くなったことは、React内部で
    // アンマウントされたことの証明ではない(移動先の画面が表示されたことの確認にとどまる)。
    await openDrafts(page, api)

    await page.getByRole('link', { name: '公開済み一覧', exact: true }).click()
    await expect(page).toHaveURL(/\/posts$/)
    await expect(page.getByRole('heading', { level: 1, name: '投稿一覧' })).toBeVisible()
    await expect(heading(page)).toHaveCount(0)

    // 移動前に始まり保留中だった取得をすべて完了させる
    await releaseAll(api, draftsOk([draft(20, '離脱後に届いた下書き')]))

    await expectStable(page, async () => {
      expect(new URL(page.url()).pathname).toBe('/posts')
      expect(await countNow(draftTitle(page, '離脱後に届いた下書き'))).toBe(0)
      expect(pageErrors).toEqual([])
      expect(await unhandledRejections(page)).toEqual([])
    })
  })
})
