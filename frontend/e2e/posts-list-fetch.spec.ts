// frontend/e2e/posts-list-fetch.spec.ts
//
// 投稿一覧(/posts)の取得処理の回帰テスト。
//
// - 取得条件はタグ(?tag=)のみ。条件の変更は、実在する導線(タグのバッジ、
//   「絞り込みを解除」リンク)とブラウザの戻る・進むで行い、page.goto は最初の1回だけ使う。
//   文書の読み込みが1回だけであること(同じ文書内での条件変更)も確認する。
// - バックエンド(http://localhost:8000)への通信はモックし、実バックエンド・DBは使わない。
//   モック対象外のバックエンド通信と、フロントエンド・バックエンド以外への通信は
//   遮断・記録し、テスト終了時に1件も無いことを確認する。
//   フロントエンド(baseURL)への通信は、Next.jsの資産・RSC・プリフェッチ等として許可する。
// - /api/posts へのリクエストには、ページ内の初期化スクリプトで呼び出し順の番号
//   (X-E2E-Call-Index)を付け、ルートで保留する。
// - 取得の「世代」: 条件を変える操作の直前のページ内の呼び出し件数を境界とし、
//   その後に始まった同じ条件の通信を、その操作の世代とする(次の操作が始まると閉じる)。
//   各操作の後は、その世代の通信が1件以上ルートに到着するまで待つ。世代に応答を決めた後に
//   同じ世代の通信が到着した場合も、決めた応答を返す。どの世代にも属さない通信は
//   テスト終了時に想定外として検知する。
//   開発時の Strict Mode では1回の表示で取得が複数回始まりうるが、Strict Mode のテスト以外は
//   回数に依存しない。
// - アプリが応答を読み終えたか(本文の読み取り完了・失敗、または通信失敗)までを記録する。
//   React の状態反映の完了は直接は観測できないため、表示されることは再試行付き
//   アサーションで、表示が維持されることは有限の観測時間(OBSERVATION_MS)で確認する。
//   観測時間以降も変化しないことの保証ではない。
// - 未処理のPromise拒否は、ページのスクリプトより前に設置した unhandledrejection の
//   記録と pageerror で確認する。

import {
  test as base,
  expect,
  type Locator,
  type Page,
  type Route,
} from '@playwright/test'

const BACKEND_ORIGIN = 'http://localhost:8000'
const CALL_INDEX_HEADER = 'X-E2E-Call-Index'

const OBSERVATION_MS = 1_000
const OBSERVATION_INTERVAL_MS = 50

type Tag = { id: number; name: string; slug: string }

const ENERGY: Tag = { id: 1, name: 'エネルギー', slug: 'energy' }
const EDUCATION: Tag = { id: 2, name: '教育', slug: 'education' }

type MockResponse =
  | { kind: 'json'; status: number; body: unknown }
  | { kind: 'raw'; status: number; body: string }
  | { kind: 'network-error' }

type PageCall = { index: number; tag: string | null }

type HeldPosts = {
  callIndex: number
  /** リクエストの ?tag= の値(指定なしは null) */
  tag: string | null
  released: boolean
  /** 応答を返す(本文の読み取りは待たない) */
  respond: (response: MockResponse) => void
}

/** 1回の条件変更(操作)で始まった取得の世代 */
type Generation = {
  /** 到着済みの通信(呼び出し順) */
  arrived: () => HeldPosts[]
  /** 到着済みの通信が count 件以上になるまで待つ */
  waitForArrivals: (count: number) => Promise<HeldPosts[]>
  /**
   * この世代の通信すべて(到着済み・今後到着するもの)に同じ応答を返し、
   * 到着済みの通信について、アプリが本文を読み終える(または通信失敗を受け取る)まで待つ
   */
  release: (response: MockResponse) => Promise<void>
  /** 指定した通信だけに応答を返し、アプリが本文を読み終えるまで待つ */
  releaseOne: (item: HeldPosts, response: MockResponse) => Promise<void>
}

type Api = {
  /**
   * action の直前のページ内の呼び出し件数を境界に、tag の取得の新しい世代を始め、
   * action を実行して、その世代の通信が1件以上到着するまで待つ
   */
  startGeneration: (
    tag: string | null,
    action: () => Promise<unknown>,
  ) => Promise<Generation>
}

declare global {
  interface Window {
    __postsE2E: {
      calls: () => PageCall[]
      whenSettled: (callIndex: number) => Promise<void>
      unhandledRejections: () => string[]
    }
  }
}

// 実際のAPI(PostController::index: PostPresenter::format + meta)と同じ応答形式
const post = (id: number, title: string, tags: Tag[]) => ({
  id,
  user_id: 100 + id,
  category_id: 1,
  title,
  view_count: 0,
  published_at: '2026-09-01T00:00:00.000000Z',
  created_at: '2026-09-01T00:00:00.000000Z',
  updated_at: '2026-09-01T00:00:00.000000Z',
  bookmark_count: 0,
  user: {
    id: 100 + id,
    last_name: '投稿者',
    first_name: null,
    age: null,
    region: null,
    trust_score: { total_score: 10, max_score: 50 },
    identity_verified: false,
  },
  category: { id: 1, name: '政治', slug: 'politics' },
  tags,
})

type MockPost = ReturnType<typeof post>

const postsOk = (posts: MockPost[]): MockResponse => ({
  kind: 'json',
  status: 200,
  body: {
    posts,
    meta: { current_page: 1, last_page: 1, per_page: 20, total: posts.length },
  },
})

const serverError: MockResponse = {
  kind: 'json',
  status: 500,
  body: { message: 'Server Error' },
}

// 存在しないタグを指定した場合の検証エラー(PostController::index の validate)
const validationError: MockResponse = {
  kind: 'json',
  status: 422,
  body: {
    message: 'The selected tag is invalid.',
    errors: { tag: ['The selected tag is invalid.'] },
  },
}

/** 全件の一覧(エネルギー・教育の両方のタグを持つ投稿を含む) */
const ALL_POSTS = [
  post(1, '全件の投稿1', [ENERGY, EDUCATION]),
  post(2, '全件の投稿2', [EDUCATION]),
]

/**
 * ページの読み込み前(アプリのスクリプトより前)に実行する初期化スクリプト。
 * - 未処理のPromise拒否(unhandledrejection)を記録する。
 * - /api/posts の呼び出しを同期的に記録して番号を付け、本文の読み取り完了・失敗、
 *   または通信失敗を記録する。番号のヘッダーは、元のリクエスト(ヘッダー・メソッド等の
 *   設定)を複製したうえで追加する。React の状態反映の完了は記録しない。
 */
function E2E_INIT_SCRIPT({
  backendOrigin,
  callIndexHeader,
}: {
  backendOrigin: string
  callIndexHeader: string
}) {
  localStorage.removeItem('openpersona_token')

  const unhandledRejections: string[] = []
  window.addEventListener('unhandledrejection', (event) => {
    unhandledRejections.push(String(event.reason))
  })

  const calls: PageCall[] = []
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

    if (url.origin !== backendOrigin || url.pathname !== '/api/posts') {
      return originalFetch(input, init)
    }

    const index = calls.length + 1
    calls.push({ index, tag: url.searchParams.get('tag') })
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

  window.__postsE2E = {
    calls: () => calls.map((call) => ({ ...call })),
    whenSettled: (callIndex) =>
      settled.has(callIndex)
        ? Promise.resolve()
        : new Promise<void>((resolve) => {
            waiters.set(callIndex, [...(waiters.get(callIndex) ?? []), resolve])
          }),
    unhandledRejections: () => [...unhandledRejections],
  }
}

const test = base.extend<{ api: Api }>({
  // すべてのテストでモック・通信遮断を有効にするため、テストが参照しなくても自動で実行する。
  // 第2引数は慣例では use だが、ReactのHook(use)と誤認されないよう別名にする
  api: [
    async ({ page, baseURL }, provide) => {
      if (!baseURL) {
        throw new Error('playwright.config.ts の baseURL が未設定です')
      }

      const frontendOrigin = new URL(baseURL).origin
      const corsHeaders = {
        'Access-Control-Allow-Origin': frontendOrigin,
        'Access-Control-Allow-Methods': 'GET, OPTIONS',
        'Access-Control-Allow-Headers': `Accept, ${CALL_INDEX_HEADER}`,
      }
      const unexpected: string[] = []
      const held: HeldPosts[] = []

      type GenerationState = {
        tag: string | null
        /** この番号より後(を含まない)の呼び出しが対象 */
        from: number
        /** この番号まで(を含む)の呼び出しが対象。null は次の世代が始まっていない */
        to: number | null
        members: HeldPosts[]
        policy: MockResponse | null
        waiters: Array<() => void>
      }
      const generations: GenerationState[] = []
      const unassigned: HeldPosts[] = []

      const findGeneration = (item: HeldPosts) =>
        generations.find(
          (generation) =>
            generation.tag === item.tag &&
            item.callIndex > generation.from &&
            (generation.to === null || item.callIndex <= generation.to),
        )

      const whenSettled = (item: HeldPosts) =>
        page.evaluate((index) => window.__postsE2E.whenSettled(index), item.callIndex)

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

          if (method === 'OPTIONS' && url.pathname === '/api/posts') {
            await route.fulfill({ status: 204, headers: corsHeaders })
            return
          }

          if (method === 'GET' && url.pathname === '/api/posts') {
            const callIndex = Number(
              (await request.headerValue(CALL_INDEX_HEADER)) ?? Number.NaN,
            )
            let resolveResponse!: (response: MockResponse) => void
            const responsePromise = new Promise<MockResponse>((resolve) => {
              resolveResponse = resolve
            })

            const item: HeldPosts = {
              callIndex,
              tag: url.searchParams.get('tag'),
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
            const generation = findGeneration(item)

            if (!generation) {
              unassigned.push(item)
            } else {
              generation.members.push(item)
              generation.members.sort((x, y) => x.callIndex - y.callIndex)
              for (const notify of generation.waiters.splice(0)) {
                notify()
              }
              if (generation.policy) {
                item.respond(generation.policy)
              }
            }

            await fulfill(route, await responsePromise)
            return
          }

          unexpected.push(`${method} ${url.href}`)
          await route.abort()
        },
      )

      await page.addInitScript(E2E_INIT_SCRIPT, {
        backendOrigin: BACKEND_ORIGIN,
        callIndexHeader: CALL_INDEX_HEADER,
      })

      const pageCallCount = () =>
        page
          .evaluate(() => window.__postsE2E?.calls().length ?? 0)
          .catch(() => 0)

      await provide({
        startGeneration: async (tag, action) => {
          const mark = await pageCallCount()

          for (const generation of generations) {
            if (generation.to === null) {
              generation.to = mark
            }
          }

          const state: GenerationState = {
            tag,
            from: mark,
            to: null,
            members: [],
            policy: null,
            waiters: [],
          }
          generations.push(state)

          const waitForArrivals = async (count: number) => {
            while (state.members.length < count) {
              await new Promise<void>((resolve) => state.waiters.push(resolve))
            }
            return [...state.members]
          }

          await action()
          await waitForArrivals(1)

          return {
            arrived: () => [...state.members],
            waitForArrivals,
            release: async (response) => {
              state.policy = response
              const members = [...state.members]
              for (const item of members) {
                item.respond(response)
              }
              for (const item of members) {
                await whenSettled(item)
              }
            },
            releaseOne: async (item, response) => {
              item.respond(response)
              await whenSettled(item)
            },
          }
        },
      })

      for (const item of held) {
        item.respond({ kind: 'network-error' })
      }

      expect(unexpected, '想定外の通信がありました').toEqual([])
      expect(
        unassigned.map((item) => `#${item.callIndex} tag=${item.tag}`),
        'どの取得の世代にも属さない /api/posts の通信がありました',
      ).toEqual([])
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
  return page.evaluate(() => window.__postsE2E.unhandledRejections())
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

const postTitle = (page: Page, title: string) =>
  page.getByRole('heading', { level: 2, name: title, exact: true })
const anyPostTitle = (page: Page) => page.getByRole('heading', { level: 2 })
const loadingStatus = (page: Page) =>
  page.getByRole('status').filter({ hasText: '読み込み中...' })
const errorStatus = (page: Page) =>
  page.getByRole('status').filter({ hasText: '投稿一覧取得に失敗しました。' })
const emptyAll = (page: Page) => page.getByText('まだ投稿がありません。')
const emptyTag = (page: Page) => page.getByText('このタグの投稿はまだありません。')
const tagBadge = (page: Page, tag: Tag) =>
  page.getByRole('link', { name: `#${tag.name}`, exact: true }).first()
const clearFilter = (page: Page) =>
  page.getByRole('link', { name: '絞り込みを解除', exact: true })
/** 絞り込み表示の「#タグ名」部分 */
const filterLabel = (page: Page) =>
  page.locator('span:has-text("で絞り込み中") > span.font-medium')

/** 全件の一覧を開いて表示させる */
async function openAllPosts(page: Page, api: Api) {
  const initial = await api.startGeneration(null, () => page.goto('/posts'))
  await initial.release(postsOk(ALL_POSTS))
  await expect(postTitle(page, '全件の投稿1')).toBeVisible()
}

/** 読み込み中は、読み込み表示だけで、一覧・空一覧・エラーを表示していない */
async function expectLoadingOnlyNow(page: Page) {
  expect(await countNow(loadingStatus(page))).toBe(1)
  expect(await countNow(anyPostTitle(page))).toBe(0)
  expect(await countNow(emptyAll(page))).toBe(0)
  expect(await countNow(emptyTag(page))).toBe(0)
  expect(await countNow(errorStatus(page))).toBe(0)
}

let documentLoads: () => number
let pageErrors: string[]

test.beforeEach(({ page }) => {
  documentLoads = countDocumentLoads(page)
  pageErrors = collectPageErrors(page)
})

test.afterEach(async ({ page }) => {
  // 条件の変更は同じ文書内で行われ、未処理の例外・Promise拒否が無い
  expect(documentLoads()).toBe(1)
  expect(pageErrors).toEqual([])
  expect(await unhandledRejections(page)).toEqual([])
})

test.describe('表示', () => {
  test('取得前は読み込み表示だけで、空一覧のメッセージを出さない', async ({ page, api }) => {
    const initial = await api.startGeneration(null, () => page.goto('/posts'))

    await expect(loadingStatus(page)).toBeVisible()
    await expectStable(page, () => expectLoadingOnlyNow(page))

    await initial.release(postsOk(ALL_POSTS))
    await expect(postTitle(page, '全件の投稿1')).toBeVisible()
    await expect(loadingStatus(page)).toHaveCount(0)
  })

  test('一覧を表示し、タグのバッジは /posts?tag= へリンクする', async ({ page, api }) => {
    await openAllPosts(page, api)

    await expect(postTitle(page, '全件の投稿2')).toBeVisible()
    await expect(tagBadge(page, ENERGY)).toHaveAttribute('href', '/posts?tag=energy')
    await expect(emptyAll(page)).toHaveCount(0)
    await expect(errorStatus(page)).toHaveCount(0)
  })

  test('タグ指定なしの空一覧', async ({ page, api }) => {
    const initial = await api.startGeneration(null, () => page.goto('/posts'))
    await initial.release(postsOk([]))

    await expect(emptyAll(page)).toBeVisible()
    await expect(loadingStatus(page)).toHaveCount(0)
  })

  test('タグ指定ありの空一覧では、タグの表示名が分からないためslugを表示する', async ({
    page,
    api,
  }) => {
    await openAllPosts(page, api)

    const energy = await api.startGeneration('energy', () =>
      tagBadge(page, ENERGY).click(),
    )
    await expect(page).toHaveURL(/\/posts\?tag=energy$/)
    // 取得前はタグの表示名が分からないため、slugを表示する
    await expect(filterLabel(page)).toHaveText('#energy')
    await expect(clearFilter(page)).toBeVisible()

    await energy.release(postsOk([]))
    await expect(emptyTag(page)).toBeVisible()
    await expect(filterLabel(page)).toHaveText('#energy')
  })

  test('条件変更後の読み込み中は、前の条件の一覧を隠し、解除リンクは表示する', async ({
    page,
    api,
  }) => {
    await openAllPosts(page, api)

    const energy = await api.startGeneration('energy', () =>
      tagBadge(page, ENERGY).click(),
    )
    await expect(loadingStatus(page)).toBeVisible()
    await expectStable(page, async () => {
      await expectLoadingOnlyNow(page)
      expect(await countNow(clearFilter(page))).toBe(1)
    })

    await energy.release(postsOk([post(10, 'エネルギーの投稿', [ENERGY])]))
    await expect(postTitle(page, 'エネルギーの投稿')).toBeVisible()
    // 取得した一覧に含まれるタグから表示名を求める
    await expect(filterLabel(page)).toHaveText('#エネルギー')
  })
})

test.describe('エラー', () => {
  const failures: Array<[string, MockResponse]> = [
    ['422(存在しないタグ)', validationError],
    ['500', serverError],
    ['通信失敗', { kind: 'network-error' }],
    ['不正なJSON', { kind: 'raw', status: 200, body: '<html>not json</html>' }],
  ]

  for (const [label, response] of failures) {
    test(`${label}の場合はエラーを表示し、前の条件の一覧を残さない`, async ({
      page,
      api,
    }) => {
      await openAllPosts(page, api)

      const energy = await api.startGeneration('energy', () =>
        tagBadge(page, ENERGY).click(),
      )
      await energy.release(response)

      await expect(errorStatus(page)).toBeVisible()
      await expectStable(page, async () => {
        expect(await countNow(errorStatus(page))).toBe(1)
        expect(await countNow(anyPostTitle(page))).toBe(0)
        expect(await countNow(emptyTag(page))).toBe(0)
        expect(await countNow(loadingStatus(page))).toBe(0)
      })
    })
  }
})

test.describe('応答順', () => {
  test('古い条件の応答が後から届いても、新しい条件の一覧を上書きしない', async ({
    page,
    api,
  }) => {
    await openAllPosts(page, api)

    const energy = await api.startGeneration('energy', () =>
      tagBadge(page, ENERGY).click(),
    )
    const all = await api.startGeneration(null, () => clearFilter(page).click())
    await expect(page).toHaveURL(/\/posts$/)

    await all.release(postsOk([post(20, '解除後の全件', [])]))
    await expect(postTitle(page, '解除後の全件')).toBeVisible()

    await energy.release(postsOk([post(21, '古いエネルギーの投稿', [ENERGY])]))
    await expectStable(page, async () => {
      expect(await countNow(postTitle(page, '解除後の全件'))).toBe(1)
      expect(await countNow(postTitle(page, '古いエネルギーの投稿'))).toBe(0)
    })
  })

  test('新しい条件の取得中に古い条件の応答が先に届いても、読み込み表示のまま', async ({
    page,
    api,
  }) => {
    await openAllPosts(page, api)

    const energy = await api.startGeneration('energy', () =>
      tagBadge(page, ENERGY).click(),
    )
    const all = await api.startGeneration(null, () => clearFilter(page).click())

    await energy.release(postsOk([post(21, '古いエネルギーの投稿', [ENERGY])]))
    await expectStable(page, () => expectLoadingOnlyNow(page))

    await all.release(postsOk([post(20, '解除後の全件', [])]))
    await expect(postTitle(page, '解除後の全件')).toBeVisible()
  })

  test('古い条件の失敗は、後から届いても先に届いても新しい条件の表示にエラーを出さない', async ({
    page,
    api,
  }) => {
    await openAllPosts(page, api)

    // 先に届く古い失敗
    const energy = await api.startGeneration('energy', () =>
      tagBadge(page, ENERGY).click(),
    )
    const all = await api.startGeneration(null, () => clearFilter(page).click())
    await energy.release(serverError)
    await expectStable(page, () => expectLoadingOnlyNow(page))

    await all.release(postsOk([post(20, '解除後の全件', [ENERGY])]))
    await expect(postTitle(page, '解除後の全件')).toBeVisible()

    // 後から届く古い失敗
    const energyAgain = await api.startGeneration('energy', () =>
      tagBadge(page, ENERGY).click(),
    )
    const back = await api.startGeneration(null, () => page.goBack())
    await expect(page).toHaveURL(/\/posts$/)
    await back.release(postsOk([post(22, '戻った後の全件', [])]))
    await expect(postTitle(page, '戻った後の全件')).toBeVisible()

    await energyAgain.release(serverError)
    await expectStable(page, async () => {
      expect(await countNow(errorStatus(page))).toBe(0)
      expect(await countNow(postTitle(page, '戻った後の全件'))).toBe(1)
    })
  })

  test('A成功→B保留→Aへ戻ると、新しいAの取得中は前のAの一覧を表示しない', async ({
    page,
    api,
  }) => {
    await openAllPosts(page, api)

    // A(エネルギー)の取得に成功する
    const firstA = await api.startGeneration('energy', () =>
      tagBadge(page, ENERGY).click(),
    )
    await firstA.release(
      postsOk([post(30, '1回目のエネルギーの投稿', [ENERGY, EDUCATION])]),
    )
    await expect(postTitle(page, '1回目のエネルギーの投稿')).toBeVisible()

    // Aの一覧のバッジからB(教育)へ移動し、Bの取得を保留する
    const b = await api.startGeneration('education', () =>
      tagBadge(page, EDUCATION).click(),
    )
    await expect(page).toHaveURL(/\/posts\?tag=education$/)

    // ブラウザの戻るでAへ戻る。新しいAの取得中は、前のAの一覧を表示しない
    const secondA = await api.startGeneration('energy', () => page.goBack())
    await expect(page).toHaveURL(/\/posts\?tag=energy$/)
    await expect(loadingStatus(page)).toBeVisible()
    await expectStable(page, () => expectLoadingOnlyNow(page))

    await secondA.release(postsOk([post(31, '2回目のエネルギーの投稿', [ENERGY])]))
    await expect(postTitle(page, '2回目のエネルギーの投稿')).toBeVisible()

    // 保留していたBの応答が最後に届いても変わらない
    await b.release(postsOk([post(32, '教育の投稿', [EDUCATION])]))
    await expectStable(page, async () => {
      expect(await countNow(postTitle(page, '2回目のエネルギーの投稿'))).toBe(1)
      expect(await countNow(postTitle(page, '1回目のエネルギーの投稿'))).toBe(0)
      expect(await countNow(postTitle(page, '教育の投稿'))).toBe(0)
    })
  })
})

test.describe('Strict Mode', () => {
  test('同じ条件で cleanup 済みの取得の応答は、最新の取得の結果を上書きしない', async ({
    page,
    api,
  }) => {
    // 開発時の Strict Mode では、クライアント側で新しくマウントされた PostsListSection の
    // effect が setup → cleanup → setup と実行され、同じ条件の取得が2回始まる。
    // (実行して確認した結果、最初の文書読み込みでハイドレーションされた場合は1回だけだったため、
    // タグのバッジによるクライアント側の移動で観測する)
    // cleanup の動作を観測するため、1回目(cleanup 済み)の取得にだけ別の一覧を返す
    // 人工的な応答を使う(実際のAPIがこう応答することを想定したものではない)。
    await openAllPosts(page, api)

    const energy = await api.startGeneration('energy', () =>
      tagBadge(page, ENERGY).click(),
    )
    // 実際に2件が到着するまで待つ(到着しなければタイムアウトで失敗する)
    const [cleanedUp, latest] = await energy.waitForArrivals(2)

    await energy.releaseOne(
      latest,
      postsOk([post(40, '最新の取得のエネルギーの投稿', [ENERGY])]),
    )
    await expect(postTitle(page, '最新の取得のエネルギーの投稿')).toBeVisible()

    await energy.releaseOne(
      cleanedUp,
      postsOk([post(41, 'cleanup済みの取得の投稿', [ENERGY])]),
    )
    await expectStable(page, async () => {
      expect(await countNow(postTitle(page, '最新の取得のエネルギーの投稿'))).toBe(1)
      expect(await countNow(postTitle(page, 'cleanup済みの取得の投稿'))).toBe(0)
      // 3件目以降の取得は始まっていない
      expect(energy.arrived()).toHaveLength(2)
    })
  })
})
