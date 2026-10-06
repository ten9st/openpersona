// frontend/e2e/edit-post-fetch.spec.ts
//
// 投稿編集画面(/posts/{id}/edit)の取得処理と、フォームの初期化・未保存の入力の回帰テスト。
//
// - 画面は3層で構成される: 投稿IDを読む EditPostPage、key={postId} で作り直される
//   EditPostLoader(投稿・カテゴリの取得と表示の切り替え)、取得した投稿を初期値にする
//   EditPostForm(入力と保存・公開・コピー)。
// - バックエンド(http://localhost:8000)への通信はモックし、実バックエンド・DBは使わない。
//   モック対象外のバックエンド通信と、フロントエンド・バックエンド以外への通信は
//   遮断・記録し、テスト終了時に1件も無いことを確認する。
//   フロントエンド(baseURL)への通信は、Next.jsの資産・RSC・プリフェッチ等として許可する。
// - GET /api/posts/{id} には、ページ内の初期化スクリプトで呼び出し順の番号
//   (X-E2E-Call-Index)を付け、ルートで保留する。テストが応答を決めるまで返さない。
// - 応答を返した後は、アプリが本文を読み終えた(json() の完了・失敗)こと、または
//   通信失敗を受け取ったことまでを記録して待つ。これは React の状態反映・描画の完了ではない。
//   表示されることは再試行付きアサーションで確認し、表示が維持されること(上書きされない
//   こと)は、有限の観測時間(OBSERVATION_MS)のあいだ変化しないことで確認する。
//   観測時間以降も変化しないことの保証ではない。
// - 未処理のPromise拒否は、ページのスクリプトより前に設置した unhandledrejection の
//   記録と pageerror で確認する。
//
// 検証の限界と、実行段階での確認予定:
// - 取得の世代の抑止(ignore)の検出候補は A1・A2。ignore を外した改変版で、
//   A1 はエラー表示への切り替え、A2 は古い成功の表示・最新値が入らないことで失敗する想定。
// - B1・B2 は未保存の入力の維持の確認で、ignore の検出には使わない(フォームは取得した投稿を
//   初期値にしか使わないため、ignore が無くても入力は変わらない想定)。
// - 投稿IDの変更(コピーと戻る)は、key による作り直しの確認候補。ただし Next.js が画面遷移で
//   EditPostLoader を作り直す場合は、key を外しても成功しうる(その場合は、この経路では
//   key の検出力を確認できない)。離脱後に届いた応答は、アンマウント済みであれば ignore が
//   無くても画面に影響しないため、ignore の検出には使わない。

import { test as base, expect, type Locator, type Page, type Route } from '@playwright/test'

const BACKEND_ORIGIN = 'http://localhost:8000'
const CALL_INDEX_HEADER = 'X-E2E-Call-Index'
const TOKEN_KEY = 'openpersona_token'
const DUMMY_TOKEN = 'e2e-dummy-token'

const LOADING_MESSAGE = '読み込み中...'
const FETCH_ERROR_MESSAGE = '投稿の取得に失敗しました。'

const OBSERVATION_MS = 1_000
const OBSERVATION_INTERVAL_MS = 50

/** GET /api/posts/{id} のパス(数字のIDのみ。/api/posts/drafts は含まない) */
const POST_PATH_PATTERN = /^\/api\/posts\/(\d+)$/

type MockResponse =
  | { kind: 'json'; status: number; body: unknown }
  | { kind: 'raw'; status: number; body: string }
  | { kind: 'network-error' }

type CategoriesMock = 'ok' | 'hold' | 'server-error' | 'network-error'

type HeldPost = {
  callIndex: number
  postId: string
  authorization: string | null
  released: boolean
  /** 応答を返す(本文の読み取りは待たない) */
  respond: (response: MockResponse) => void
}

type Api = {
  /** 到着済みの GET /api/posts/{id}(呼び出し順)。postId を指定するとその投稿だけ */
  arrived: (postId?: string) => HeldPost[]
  /** postId の取得が count 件以上到着するまで待つ(届かなければテストのタイムアウトで失敗する) */
  waitForArrivals: (postId: string, count: number) => Promise<HeldPost[]>
  /** 応答を返し、アプリが本文を読み終える(または通信失敗を受け取る)まで待つ */
  release: (item: HeldPost, response: MockResponse) => Promise<void>
  /** categoriesMock が 'hold' のとき、保留中のカテゴリ取得に応答を返す */
  releaseCategories: (response: MockResponse) => void
  /** 下書き保存・公開(PUT /api/posts/{id})で送られた本文 */
  updates: () => { postId: string; body: Record<string, unknown> }[]
}

declare global {
  interface Window {
    __editE2E: {
      /** ページ内で始まった GET /api/posts/{id} の呼び出し件数 */
      postCalls: () => number
      whenSettled: (callIndex: number) => Promise<void>
      unhandledRejections: () => string[]
    }
  }
}

// 実際のAPIと同じ応答形式
// - GET /api/categories: CategoryController::index({ categories })
// - GET /api/posts/{id}: PostController::show({ post })。編集画面が使う項目を中心に含める
// - POST /api/posts/{id}/copy: PostController::copy({ message, copied_from_post_id, post })
// - PUT /api/posts/{id}: PostController::update({ message, post })
// - GET /api/posts/drafts: PostController::drafts({ posts })
const CATEGORIES = [
  { id: 1, name: '政治', slug: 'politics', posting_age_limit: null, sort_order: 1 },
  { id: 2, name: '経済', slug: 'economy', posting_age_limit: null, sort_order: 2 },
  { id: 3, name: '教育', slug: 'education', posting_age_limit: null, sort_order: 3 },
]

type PostOverrides = {
  title?: string
  body?: string
  status?: 'draft' | 'published'
  category_id?: number
  sources?: unknown[]
}

const showPost = (id: number, overrides: PostOverrides = {}) => ({
  id,
  user_id: 1,
  category_id: overrides.category_id ?? 2,
  title: overrides.title ?? `投稿${id}のタイトル`,
  body: overrides.body ?? `投稿${id}の本文`,
  status: overrides.status ?? 'draft',
  view_count: 0,
  published_at: overrides.status === 'published' ? '2026-09-01T00:00:00.000000Z' : null,
  created_at: '2026-09-01T00:00:00.000000Z',
  updated_at: '2026-09-02T00:00:00.000000Z',
  category: { id: overrides.category_id ?? 2, name: '経済', slug: 'economy' },
  user: {
    id: 1,
    last_name: '投稿者',
    first_name: null,
    age: null,
    region: null,
    trust_score: { total_score: 10, max_score: 50 },
    identity_verified: false,
  },
  sources: overrides.sources ?? [],
  tags: [],
  attachments: [],
  comments: [],
})

const postOk = (id: number, overrides: PostOverrides = {}): MockResponse => ({
  kind: 'json',
  status: 200,
  body: { post: showPost(id, overrides) },
})

const draftListItem = (id: number, title: string) => ({
  id,
  category_id: 2,
  title,
  status: 'draft',
  created_at: '2026-09-01T00:00:00.000000Z',
  updated_at: '2026-09-02T00:00:00.000000Z',
  category: { id: 2, name: '経済', slug: 'economy' },
})

/**
 * ページの読み込み前(アプリのスクリプトより前)に実行する初期化スクリプト。
 * - token が文字列ならダミートークンを置き、null なら削除する
 *   (文書の読み込みごとに実行されるため、テスト側で読み込みが1回だけであることを確認する)。
 * - 未処理のPromise拒否(unhandledrejection)を記録する。
 * - GET /api/posts/{id} の呼び出しを同期的に記録して番号を付け、本文の読み取り完了・失敗、
 *   または通信失敗を記録する。番号のヘッダーは、元のリクエスト(ヘッダー・メソッド等の
 *   設定)を複製したうえで追加する。React の状態反映の完了は記録しない。
 */
function E2E_INIT_SCRIPT({
  token,
  tokenKey,
  backendOrigin,
  callIndexHeader,
  postPathSource,
}: {
  token: string | null
  tokenKey: string
  backendOrigin: string
  callIndexHeader: string
  postPathSource: string
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

  const postPath = new RegExp(postPathSource)
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
    const method = (
      init?.method ?? (input instanceof Request ? input.method : 'GET')
    ).toUpperCase()

    if (url.origin !== backendOrigin || method !== 'GET' || !postPath.test(url.pathname)) {
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

  window.__editE2E = {
    postCalls: () => calls,
    whenSettled: (callIndex) =>
      settled.has(callIndex)
        ? Promise.resolve()
        : new Promise<void>((resolve) => {
            waiters.set(callIndex, [...(waiters.get(callIndex) ?? []), resolve])
          }),
    unhandledRejections: () => [...unhandledRejections],
  }
}

const test = base.extend<{
  token: string | null
  categoriesMock: CategoriesMock
  /** コピーで作られる新しい投稿のID */
  copiedPostId: number
  api: Api
}>({
  token: [DUMMY_TOKEN, { option: true }],
  categoriesMock: ['ok', { option: true }],
  copiedPostId: [6, { option: true }],
  // すべてのテストでモック・通信遮断を有効にするため、テストが参照しなくても自動で実行する。
  // 第2引数は慣例では use だが、ReactのHook(use)と誤認されないよう別名にする
  api: [
    async ({ page, baseURL, token, categoriesMock, copiedPostId }, provide) => {
      if (!baseURL) {
        throw new Error('playwright.config.ts の baseURL が未設定です')
      }

      const frontendOrigin = new URL(baseURL).origin
      const corsHeaders = { 'Access-Control-Allow-Origin': frontendOrigin }
      const unexpected: string[] = []
      const held: HeldPost[] = []
      const arrivalWaiters: Array<() => void> = []
      const updates: { postId: string; body: Record<string, unknown> }[] = []

      let resolveCategories!: (response: MockResponse) => void
      const heldCategories = new Promise<MockResponse>((resolve) => {
        resolveCategories = resolve
      })

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

      const categoriesResponse = (): Promise<MockResponse> | MockResponse => {
        switch (categoriesMock) {
          case 'hold':
            return heldCategories
          case 'server-error':
            return { kind: 'json', status: 500, body: { message: 'Server Error' } }
          case 'network-error':
            return { kind: 'network-error' }
          default:
            return { kind: 'json', status: 200, body: { categories: CATEGORIES } }
        }
      }

      await page.route(
        (url) => url.origin === BACKEND_ORIGIN,
        async (route) => {
          const request = route.request()
          const url = new URL(request.url())
          const method = request.method()
          const postMatch = url.pathname.match(POST_PATH_PATTERN)

          if (method === 'GET' && postMatch) {
            const callIndex = Number(
              (await request.headerValue(CALL_INDEX_HEADER)) ?? Number.NaN,
            )
            let resolveResponse!: (response: MockResponse) => void
            const responsePromise = new Promise<MockResponse>((resolve) => {
              resolveResponse = resolve
            })

            const item: HeldPost = {
              callIndex,
              postId: postMatch[1],
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

          if (method === 'GET' && url.pathname === '/api/categories') {
            await fulfill(route, await categoriesResponse())
            return
          }

          if (method === 'PUT' && postMatch) {
            updates.push({ postId: postMatch[1], body: request.postDataJSON() })
            await fulfill(route, {
              kind: 'json',
              status: 200,
              body: { message: '下書きを保存しました。', post: showPost(Number(postMatch[1])) },
            })
            return
          }

          const copyMatch = url.pathname.match(/^\/api\/posts\/(\d+)\/copy$/)
          if (method === 'POST' && copyMatch) {
            await fulfill(route, {
              kind: 'json',
              status: 201,
              body: {
                message: '訂正用の下書きを作成しました。内容を確認して公開してください。',
                copied_from_post_id: Number(copyMatch[1]),
                post: { ...showPost(copiedPostId), sources: [] },
              },
            })
            return
          }

          // 下書き一覧(Strict Mode のケースの移動元、下書き保存後の移動先)
          if (method === 'GET' && url.pathname === '/api/posts/drafts') {
            await fulfill(route, {
              kind: 'json',
              status: 200,
              body: { posts: [draftListItem(1, '下書き1')] },
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
        callIndexHeader: CALL_INDEX_HEADER,
        postPathSource: POST_PATH_PATTERN.source,
      })

      const arrivedFor = (postId?: string) =>
        held.filter((item) => postId === undefined || item.postId === postId)

      await provide({
        arrived: (postId) => [...arrivedFor(postId)],
        waitForArrivals: async (postId, count) => {
          while (arrivedFor(postId).length < count) {
            await new Promise<void>((resolve) => arrivalWaiters.push(resolve))
          }
          return [...arrivedFor(postId)]
        },
        release: async (item, response) => {
          item.respond(response)
          await page.evaluate(
            (index) => window.__editE2E.whenSettled(index),
            item.callIndex,
          )
        },
        releaseCategories: (response) => {
          resolveCategories(response)
        },
        updates: () => updates.map((update) => ({ ...update })),
      })

      for (const item of held) {
        item.respond({ kind: 'network-error' })
      }
      // 保留中のカテゴリ取得が残っていれば通信失敗で終わらせる(応答済みなら何も起きない)
      resolveCategories({ kind: 'network-error' })

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
  return page.evaluate(() => window.__editE2E.unhandledRejections())
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

const loadingStatus = (page: Page) =>
  page.getByRole('status').filter({ hasText: LOADING_MESSAGE })
const fetchErrorStatus = (page: Page) =>
  page.getByRole('status').filter({ hasText: FETCH_ERROR_MESSAGE })
const editHeading = (page: Page) =>
  page.getByRole('heading', { level: 1, name: '下書きを編集' })
const publishedHeading = (page: Page) =>
  page.getByRole('heading', { level: 1, name: '公開済みの投稿' })
/** 投稿のタイトル欄(出典の「タイトル」欄より前にあるため、最初の1件) */
const titleInput = (page: Page) =>
  page.getByRole('textbox', { name: 'タイトル', exact: true }).first()
const bodyInput = (page: Page) => page.getByRole('textbox', { name: '本文', exact: true })
const categorySelect = (page: Page) =>
  page.getByRole('combobox', { name: 'カテゴリ', exact: true })
const anyTextbox = (page: Page) => page.getByRole('textbox')
const button = (page: Page, name: string) =>
  page.getByRole('button', { name, exact: true })

/** 読み込み中は、読み込み表示だけで、フォーム・コピー画面・エラーを表示していない */
async function expectLoadingOnlyNow(page: Page) {
  expect(await countNow(loadingStatus(page))).toBe(1)
  expect(await countNow(anyTextbox(page))).toBe(0)
  expect(await countNow(editHeading(page))).toBe(0)
  expect(await countNow(publishedHeading(page))).toBe(0)
  expect(await countNow(fetchErrorStatus(page))).toBe(0)
}

/** 取得失敗時は、エラー表示だけで、編集・保存・公開・コピーの操作が無い */
async function expectFetchErrorOnlyNow(page: Page) {
  expect(await countNow(fetchErrorStatus(page))).toBe(1)
  expect(await countNow(loadingStatus(page))).toBe(0)
  expect(await countNow(anyTextbox(page))).toBe(0)
  expect(await countNow(button(page, '公開する'))).toBe(0)
  expect(await countNow(button(page, '下書き保存'))).toBe(0)
  expect(await countNow(button(page, 'コピーして訂正投稿を作成'))).toBe(0)
}

/** 編集画面を直接開き、投稿の取得が1件以上到着するまで待つ */
async function openEdit(page: Page, api: Api, postId: string) {
  await page.goto(`/posts/${postId}/edit`)
  return api.waitForArrivals(postId, 1)
}

/** postId の到着済みで未応答の取得すべてに同じ応答を返す */
async function releaseAll(api: Api, postId: string, response: MockResponse) {
  for (const item of api.arrived(postId).filter((held) => !held.released)) {
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
  test('取得前は読み込み表示だけで、フォームを表示しない', async ({ page, api }) => {
    await openEdit(page, api, '1')

    await expect(loadingStatus(page)).toBeVisible()
    await expectStable(page, () => expectLoadingOnlyNow(page))

    // トークンは従来どおり Authorization ヘッダーで送る
    expect(api.arrived('1')[0].authorization).toBe(`Bearer ${DUMMY_TOKEN}`)

    await releaseAll(api, '1', postOk(1))
    await expect(editHeading(page)).toBeVisible()
    await expect(loadingStatus(page)).toHaveCount(0)
  })

  test('取得した投稿の値と出典でフォームを初期化する', async ({ page, api }) => {
    await openEdit(page, api, '1')
    await releaseAll(
      api,
      '1',
      postOk(1, {
        title: '編集対象のタイトル',
        body: '編集対象の本文',
        category_id: 3,
        sources: [
          {
            id: 10,
            source_type: 'url',
            title: '参考記事',
            url: 'https://example.com/article',
            note: null,
          },
        ],
      }),
    )

    await expect(editHeading(page)).toBeVisible()
    await expect(titleInput(page)).toHaveValue('編集対象のタイトル')
    await expect(bodyInput(page)).toHaveValue('編集対象の本文')
    await expect(categorySelect(page)).toBeEnabled()
    await expect(categorySelect(page)).toHaveValue('3')
    await expect(page.getByPlaceholder('例: 参考記事のタイトル')).toHaveValue('参考記事')
    await expect(page.getByPlaceholder('https://example.com/...')).toHaveValue(
      'https://example.com/article',
    )
    await expect(button(page, '公開する')).toBeVisible()
    await expect(button(page, '下書き保存')).toBeVisible()
  })

  test('公開済みの投稿は編集できず、コピー画面を表示する', async ({ page, api }) => {
    await openEdit(page, api, '5')
    await releaseAll(api, '5', postOk(5, { status: 'published' }))

    await expect(publishedHeading(page)).toBeVisible()
    await expect(button(page, 'コピーして訂正投稿を作成')).toBeVisible()
    await expect(anyTextbox(page)).toHaveCount(0)
    await expect(button(page, '公開する')).toHaveCount(0)
    await expect(button(page, '下書き保存')).toHaveCount(0)
  })
})

test.describe('トークンなし', () => {
  test.use({ token: null })

  test('投稿を取得せずに /login へ移動する', async ({ page }) => {
    await page.goto('/posts/1/edit')

    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByRole('heading', { level: 1, name: 'ログイン' })).toBeVisible()

    // 投稿API(GET /api/posts/{id})を呼んでいない(カテゴリAPIについては確認しない)
    expect(await page.evaluate(() => window.__editE2E.postCalls())).toBe(0)
  })
})

test.describe('投稿の取得失敗', () => {
  const failures: Array<[string, MockResponse]> = [
    ['401', { kind: 'json', status: 401, body: { message: 'Unauthenticated.' } }],
    ['404', { kind: 'json', status: 404, body: { message: 'Not Found' } }],
    ['500', { kind: 'json', status: 500, body: { message: 'Server Error' } }],
    ['通信失敗', { kind: 'network-error' }],
    ['不正なJSON', { kind: 'raw', status: 200, body: '<html>not json</html>' }],
  ]

  for (const [label, response] of failures) {
    test(`${label}の場合はエラーだけを表示し、編集・保存・公開・コピーをできない`, async ({
      page,
      api,
    }) => {
      await openEdit(page, api, '1')
      await releaseAll(api, '1', response)

      await expect(fetchErrorStatus(page)).toBeVisible()
      await expectStable(page, async () => {
        await expectFetchErrorOnlyNow(page)
        // 401 でもログイン画面へは移動しない(従来どおり)
        expect(new URL(page.url()).pathname).toBe('/posts/1/edit')
      })
      expect(api.updates()).toEqual([])
    })
  }
})

test.describe('カテゴリの取得失敗', () => {
  for (const categoriesMock of ['server-error', 'network-error'] as const) {
    test.describe(categoriesMock, () => {
      test.use({ categoriesMock })

      test('カテゴリ欄は選択不可の「読み込み中...」のままで、保存時は元の category_id を送る', async ({
        page,
        api,
      }) => {
        await openEdit(page, api, '1')
        await releaseAll(api, '1', postOk(1, { category_id: 3, title: '元のタイトル' }))

        await expect(editHeading(page)).toBeVisible()
        await expect(titleInput(page)).toHaveValue('元のタイトル')
        await expect(categorySelect(page)).toBeDisabled()
        await expect(categorySelect(page).locator('option')).toHaveText([LOADING_MESSAGE])

        await button(page, '下書き保存').click()

        await expect(page).toHaveURL(/\/posts\/drafts$/)
        expect(api.updates()).toHaveLength(1)
        expect(api.updates()[0].postId).toBe('1')
        expect(api.updates()[0].body).toMatchObject({
          category_id: 3,
          title: '元のタイトル',
          status: 'draft',
        })
      })
    })
  }
})

test.describe('後処理済みの応答(取得の世代の抑止)', () => {
  // 開発時の Strict Mode では、クライアント側で新しくマウントされたページの effect が
  // setup → cleanup → setup と実行され、投稿の取得が2回始まりうる。下書き一覧の
  // 「編集する」リンクからのクライアント側の移動で観測する(直接アクセスのハイドレーション
  // では1回だけの可能性がある)。2件目が届かない場合は waitForArrivals がタイムアウトし、
  // このテストの前提(2件の取得)を満たさない失敗になる。黙って成功・skipにはしない。
  // 呼び出し順の1件目を後処理済み、2件目を最新の取得とみなす。1件目の cleanup の完了
  // そのものは観測しない(2件届いたことは cleanup の完了の証明ではない)。
  // cleanup の動作を観測するため、1件目にだけ別の結果を返す人工的な応答を使う。

  /** 下書き一覧からクライアント側で編集画面へ移動し、2件の取得の到着を待つ */
  async function moveToEditFromDrafts(page: Page, api: Api) {
    await page.goto('/posts/drafts')
    await page.getByRole('link', { name: /^編集する\s*：下書き1$/ }).click()
    await expect(page).toHaveURL(/\/posts\/1\/edit$/)

    const [cleanedUp, latest] = await api.waitForArrivals('1', 2)
    return { cleanedUp, latest }
  }

  test('A1: 最新の取得の成功後に、後処理済みの取得の失敗が届いても、エラー画面に切り替えない', async ({
    page,
    api,
  }) => {
    const { cleanedUp, latest } = await moveToEditFromDrafts(page, api)

    await api.release(latest, postOk(1, { title: '最新のタイトル' }))
    await expect(titleInput(page)).toHaveValue('最新のタイトル')

    await api.release(cleanedUp, { kind: 'network-error' })
    await expectStable(page, async () => {
      expect(await countNow(fetchErrorStatus(page))).toBe(0)
      expect(await countNow(editHeading(page))).toBe(1)
      expect(await countNow(titleInput(page))).toBe(1)
      // 3件目以降の取得は始まっていない
      expect(api.arrived('1')).toHaveLength(2)
    })
  })

  test('A2: 後処理済みの取得の成功が先に届いても読み込み表示のままで、最新の取得の値で初期化する', async ({
    page,
    api,
  }) => {
    const { cleanedUp, latest } = await moveToEditFromDrafts(page, api)

    await api.release(cleanedUp, postOk(1, { title: '古いタイトル' }))
    await expectStable(page, () => expectLoadingOnlyNow(page))

    await api.release(latest, postOk(1, { title: '最新のタイトル' }))
    await expect(titleInput(page)).toHaveValue('最新のタイトル')
  })
})

test.describe('未保存の入力の維持', () => {
  test('B1: 入力後に後処理済みの取得の成功が届いても、入力を保つ', async ({ page, api }) => {
    // 入力の維持の確認であり、取得の世代の抑止(ignore)の検出には使わない。
    // フォームは取得した投稿を初期値にしか使わないため、ignore が無くても入力は変わらない想定。
    await page.goto('/posts/drafts')
    await page.getByRole('link', { name: /^編集する\s*：下書き1$/ }).click()
    await expect(page).toHaveURL(/\/posts\/1\/edit$/)
    const [cleanedUp, latest] = await api.waitForArrivals('1', 2)

    await api.release(latest, postOk(1, { title: '最新のタイトル' }))
    await expect(titleInput(page)).toHaveValue('最新のタイトル')
    await titleInput(page).fill('入力中のタイトル')
    await bodyInput(page).fill('入力中の本文')

    await api.release(cleanedUp, postOk(1, { title: '古いタイトル', body: '古い本文' }))
    await expectStable(page, async () => {
      expect(await titleInput(page).inputValue()).toBe('入力中のタイトル')
      expect(await bodyInput(page).inputValue()).toBe('入力中の本文')
    })
  })

  test.describe('カテゴリの応答が遅れる場合', () => {
    test.use({ categoriesMock: 'hold' })

    test('B2: 入力後にカテゴリの応答が届いても、入力と元のカテゴリを保つ', async ({
      page,
      api,
    }) => {
      await openEdit(page, api, '1')
      await releaseAll(api, '1', postOk(1, { title: '元のタイトル', category_id: 3 }))

      await expect(editHeading(page)).toBeVisible()
      await expect(categorySelect(page)).toBeDisabled()
      await titleInput(page).fill('入力中のタイトル')

      api.releaseCategories({ kind: 'json', status: 200, body: { categories: CATEGORIES } })

      await expect(categorySelect(page)).toBeEnabled()
      await expect(categorySelect(page)).toHaveValue('3')
      await expectStable(page, async () => {
        expect(await titleInput(page).inputValue()).toBe('入力中のタイトル')
      })
    })
  })
})

test.describe('投稿IDの変更', () => {
  test('コピーで新しい投稿へ移動し、取得中に戻っても、前の投稿の結果や移動先の応答を表示しない', async ({
    page,
    api,
  }) => {
    // 公開済みの投稿5 → コピー → 投稿6(取得を保留)→ 戻る → 投稿5(新しい取得)。
    // 投稿IDが変わると EditPostLoader は key により作り直され、取得結果は未取得から始まる想定。
    // Next.js が画面遷移で作り直す場合は key が無くても同じ結果になりうる(検証の限界)。
    await openEdit(page, api, '5')
    await releaseAll(api, '5', postOk(5, { status: 'published', title: '公開済みの投稿5' }))
    await expect(publishedHeading(page)).toBeVisible()
    const firstVisitCalls = api.arrived('5').length

    await button(page, 'コピーして訂正投稿を作成').click()
    await expect(page).toHaveURL(/\/posts\/6\/edit$/)
    await api.waitForArrivals('6', 1)
    // 移動先の取得中は、前の投稿(5)のコピー画面を表示しない
    await expect(loadingStatus(page)).toBeVisible()
    await expectStable(page, () => expectLoadingOnlyNow(page))

    await page.goBack()
    await expect(page).toHaveURL(/\/posts\/5\/edit$/)
    // 戻った投稿5の新しい取得が始まる
    await api.waitForArrivals('5', firstVisitCalls + 1)
    // 前回の投稿5の結果を再利用せず、読み込み表示だけ
    await expect(loadingStatus(page)).toBeVisible()
    await expectStable(page, () => expectLoadingOnlyNow(page))

    // 離脱した投稿6の保留中の応答が届いても表示しない
    await releaseAll(api, '6', postOk(6, { title: '移動先の投稿6' }))
    await expectStable(page, () => expectLoadingOnlyNow(page))

    await releaseAll(api, '5', postOk(5, { status: 'published', title: '公開済みの投稿5' }))
    await expect(publishedHeading(page)).toBeVisible()
    await expect(anyTextbox(page)).toHaveCount(0)
    await expect(page).toHaveURL(/\/posts\/5\/edit$/)
  })
})
