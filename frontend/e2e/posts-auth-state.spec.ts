// frontend/e2e/posts-auth-state.spec.ts
//
// 投稿一覧(/posts)の操作欄(ログイン状態による表示の切り替え)とログアウトの回帰テスト。
//
// - 投稿一覧はトークンの「存在」だけを見る(有効性はAPIで確認しない)。
//   ダミーのトークンを localStorage に置くだけで、認証の確認APIは呼ばれない前提とする
//   (投稿者の敬称のための /api/me は AuthorLink が呼ぶため、モックで応答する)。
// - ログイン状態の変更通知は、投稿一覧のログアウト操作(apiLogout の完了後)からだけ行う
//   限定的な仕組みで、別タブや他の処理によるトークンの変更には追従しない。
//   このテストが確認するのは、ここに書いた遷移経路(直接アクセス、ログイン画面からの移動、
//   投稿一覧からのログアウト)だけで、それ以外の経路での表示は保証しない。
// - バックエンド(http://localhost:8000)への通信はモックし、実バックエンド・DBは使わない。
//   モックの応答には、実際の通信と同じく CORS のヘッダーを付ける(ログインは
//   credentials: 'include' のため、送信元の明示と資格情報の許可を含む)。
//   CORS の事前確認(OPTIONS)は、route で通信を横取りしている間は Playwright(Chromium)が
//   自動で許可の応答を返し、route には届かない(playwright-core の crNetworkManager の
//   isInterceptedOptionsPreflight)。そのため事前確認の内容はこのテストでは検証しない。
//   モック対象外のバックエンド通信と、フロントエンド・バックエンド以外への通信は
//   遮断・記録し、テスト終了時に1件も無いことを確認する。
//   フロントエンド(baseURL)への通信は、Next.jsの資産・RSC・プリフェッチ等として許可する。
// - 未処理の例外・Promise拒否は、pageerror と、ページのスクリプトより前に設置した
//   unhandledrejection の記録で確認する。既存の問題として発生が分かっているもの
//   (モックで失敗させたログアウト通信による1件)だけを、そのテストで個別に想定し、
//   それ以外は検知する。
//
// ログアウト直後の操作欄の切り替えについて:
// - ログアウトは apiLogout の完了後に変更を通知し、続けて /login へ移動する。移動が速いため、
//   投稿一覧のまま未ログインの操作欄が表示されたことを、表示のアサーションで待って
//   確認することはできない(先に /login へ移動しうる)。
// - そこで、未ログインの操作欄の文字列がDOMへ追加されたことを MutationObserver で記録し、
//   その時点の location.pathname を添える。pathname はDOMの変更時ではなく、
//   MutationObserver のコールバック実行時の値であり、DOMの更新とURLの変更の順番を
//   厳密に証明するものではない。画面への描画(ペイント)も確認しない。
// - 最終的に /login へ移動したこと・トークンが削除されたことだけでは、通知の効果は確認できない
//   (通知が無くても移動とトークン削除は起こる)。

import { test as base, expect, type Page, type Route } from '@playwright/test'

const BACKEND_ORIGIN = 'http://localhost:8000'
const TOKEN_KEY = 'openpersona_token'

/** 未ログインの操作欄にだけ含まれるリンクの文字列 */
const GUEST_CTA = 'ログインして投稿する'

const DUMMY_TOKEN = 'e2e-dummy-token'
const LOGIN_TOKEN = 'e2e-login-token'

/** 通信失敗としたログアウトの通信を、Promise拒否の理由と対応付けるための印 */
const LOGOUT_REQUEST = 'POST /api/logout'

type LogoutMock = 'ok' | 'server-error' | 'network-error'

type Tag = { id: number; name: string; slug: string }

const ENERGY: Tag = { id: 1, name: 'エネルギー', slug: 'energy' }

type Rejection = {
  reason: string
  /** 理由がモックで失敗させたバックエンドの通信の失敗そのものなら、その通信 */
  failedRequest: string | null
}

declare global {
  interface Window {
    __authE2E: {
      /** 未ログインの操作欄の文字列がDOMへ追加されたときの pathname(記録順) */
      guestCtaInsertions: () => string[]
      unhandledRejections: () => Rejection[]
    }
  }
}

type Api = {
  /** モックで応答したバックエンドAPI */
  requests: () => string[]
  /** ログアウトAPIに送られた Authorization ヘッダー */
  logoutAuthorizations: () => (string | undefined)[]
}

/**
 * ページの読み込み前(アプリのスクリプトより前)に実行する初期化スクリプト。
 * - token が文字列ならダミートークンを置き、null なら削除する
 *   (文書の読み込みごとに実行されるため、テスト側で読み込みが1回だけであることを確認する)。
 * - バックエンドへの fetch が通信失敗で拒否された場合、その拒否の理由(エラーオブジェクト)に
 *   通信の「メソッド パス」を印として付ける。アプリの動作は変えない(同じ理由で拒否する)。
 * - 未処理のPromise拒否を、理由と印とともに記録する。
 * - 未ログインの操作欄の文字列を含むノードの追加(またはテキストの変更)を、
 *   コールバック実行時の pathname とともに記録する。
 */
function E2E_INIT_SCRIPT({
  token,
  tokenKey,
  marker,
  backendOrigin,
}: {
  token: string | null
  tokenKey: string
  marker: string
  backendOrigin: string
}) {
  if (token === null) {
    localStorage.removeItem(tokenKey)
  } else {
    localStorage.setItem(tokenKey, token)
  }

  const failedRequests = new WeakMap<object, string>()
  const originalFetch = window.fetch.bind(window)

  window.fetch = (input, init) => {
    const url = new URL(
      input instanceof Request ? input.url : String(input),
      window.location.href,
    )
    const method = (
      init?.method ?? (input instanceof Request ? input.method : 'GET')
    ).toUpperCase()

    return originalFetch(input, init).catch((error: unknown) => {
      if (url.origin === backendOrigin && typeof error === 'object' && error !== null) {
        failedRequests.set(error, `${method} ${url.pathname}`)
      }
      throw error
    })
  }

  const unhandledRejections: Rejection[] = []
  window.addEventListener('unhandledrejection', (event) => {
    const reason: unknown = event.reason
    unhandledRejections.push({
      reason: String(reason),
      failedRequest:
        typeof reason === 'object' && reason !== null
          ? (failedRequests.get(reason) ?? null)
          : null,
    })
  })

  const insertions: string[] = []
  const containsMarker = (node: Node) => (node.textContent ?? '').includes(marker)

  new MutationObserver((records) => {
    for (const record of records) {
      if (record.type === 'characterData' && containsMarker(record.target)) {
        insertions.push(location.pathname)
      }

      for (const node of Array.from(record.addedNodes)) {
        if (containsMarker(node)) {
          insertions.push(location.pathname)
        }
      }
    }
  }).observe(document, { childList: true, subtree: true, characterData: true })

  window.__authE2E = {
    guestCtaInsertions: () => [...insertions],
    unhandledRejections: () => unhandledRejections.map((item) => ({ ...item })),
  }
}

// 実際のAPIと同じ応答形式
// - GET /api/posts: PostController::index(PostPresenter::format + meta)
// - GET /api/me: AuthController::me({ user: User })
// - POST /api/login: AuthController::login({ message, token, user })
// - POST /api/logout: AuthController::logout({ message })
const POSTS_RESPONSE = {
  posts: [
    {
      id: 1,
      user_id: 101,
      category_id: 1,
      title: 'エネルギーの投稿',
      view_count: 0,
      published_at: '2026-09-01T00:00:00.000000Z',
      created_at: '2026-09-01T00:00:00.000000Z',
      updated_at: '2026-09-01T00:00:00.000000Z',
      bookmark_count: 0,
      user: {
        id: 101,
        last_name: '投稿者',
        first_name: null,
        age: null,
        region: null,
        trust_score: { total_score: 10, max_score: 50 },
        identity_verified: false,
      },
      category: { id: 1, name: '政治', slug: 'politics' },
      tags: [ENERGY],
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
}

const E2E_USER = { id: 1, email: 'e2e@example.com', last_name: 'E2E', first_name: '利用者' }

const test = base.extend<{
  token: string | null
  logoutMock: LogoutMock
  api: Api
}>({
  token: [null, { option: true }],
  logoutMock: ['ok', { option: true }],
  // すべてのテストでモック・通信遮断を有効にするため、テストが参照しなくても自動で実行する。
  // 第2引数は慣例では use だが、ReactのHook(use)と誤認されないよう別名にする
  api: [
    async ({ page, baseURL, token, logoutMock }, provide) => {
      if (!baseURL) {
        throw new Error('playwright.config.ts の baseURL が未設定です')
      }

      const frontendOrigin = new URL(baseURL).origin
      // ログインは credentials: 'include' のため、送信元を明示して資格情報を許可する
      const corsHeaders = {
        'Access-Control-Allow-Origin': frontendOrigin,
        'Access-Control-Allow-Credentials': 'true',
      }
      const unexpected: string[] = []
      const requests: string[] = []
      const logoutAuthorizations: (string | undefined)[] = []

      const json = (route: Route, status: number, body: unknown) =>
        route.fulfill({
          status,
          headers: corsHeaders,
          contentType: 'application/json',
          body: JSON.stringify(body),
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

      await page.route(
        (url) => url.origin === BACKEND_ORIGIN,
        async (route) => {
          const request = route.request()
          const url = new URL(request.url())
          const method = request.method()
          const label = `${method} ${url.pathname}`

          if (method === 'GET' && url.pathname === '/api/posts') {
            requests.push(`${label}${url.search}`)
            await json(route, 200, POSTS_RESPONSE)
            return
          }

          if (method === 'GET' && url.pathname === '/api/me') {
            requests.push(label)
            await json(route, 200, { user: E2E_USER })
            return
          }

          if (method === 'GET' && url.pathname === '/sanctum/csrf-cookie') {
            requests.push(label)
            await route.fulfill({ status: 204, headers: corsHeaders })
            return
          }

          if (method === 'POST' && url.pathname === '/api/login') {
            requests.push(label)
            await json(route, 200, {
              message: 'ログインが成功しました。',
              token: LOGIN_TOKEN,
              user: E2E_USER,
            })
            return
          }

          if (method === 'POST' && url.pathname === '/api/logout') {
            requests.push(label)
            logoutAuthorizations.push((await request.headerValue('Authorization')) ?? undefined)

            if (logoutMock === 'network-error') {
              await route.abort('failed')
            } else if (logoutMock === 'server-error') {
              await json(route, 500, { message: 'Server Error' })
            } else {
              await json(route, 200, { message: 'ログアウトしました。' })
            }
            return
          }

          unexpected.push(`${method} ${url.href}`)
          await route.abort()
        },
      )

      await page.addInitScript(E2E_INIT_SCRIPT, {
        token,
        tokenKey: TOKEN_KEY,
        marker: GUEST_CTA,
        backendOrigin: BACKEND_ORIGIN,
      })

      await provide({
        requests: () => [...requests],
        logoutAuthorizations: () => [...logoutAuthorizations],
      })

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

type PageError = { name: string; message: string }

/** ハイドレーション関連のコンソール出力と、ページ内の未捕捉例外を集める */
function collectErrors(page: Page) {
  const hydrationMessages: string[] = []
  const pageErrors: PageError[] = []

  page.on('console', (message) => {
    if (
      (message.type() === 'error' || message.type() === 'warning') &&
      /hydrat|did not match|didn't match/i.test(message.text())
    ) {
      hydrationMessages.push(`[${message.type()}] ${message.text()}`)
    }
  })

  page.on('pageerror', (error) => {
    pageErrors.push({ name: error.name, message: error.message })
  })

  return { hydrationMessages, pageErrors }
}

async function guestCtaInsertions(page: Page) {
  return page.evaluate(() => window.__authE2E.guestCtaInsertions())
}

async function unhandledRejections(page: Page) {
  return page.evaluate(() => window.__authE2E.unhandledRejections())
}

async function storedToken(page: Page) {
  return page.evaluate((key) => localStorage.getItem(key), TOKEN_KEY)
}

const guestCta = (page: Page) =>
  page.getByRole('link', { name: GUEST_CTA, exact: true })
const createLink = (page: Page) =>
  page.getByRole('link', { name: '投稿する', exact: true })
const logoutButton = (page: Page) =>
  page.getByRole('button', { name: 'ログアウト', exact: true })
const listHeading = (page: Page) =>
  page.getByRole('heading', { level: 1, name: '投稿一覧' })
const loginHeading = (page: Page) =>
  page.getByRole('heading', { level: 1, name: 'ログイン' })
const filterLabel = (page: Page) =>
  page.locator('span:has-text("で絞り込み中") > span.font-medium')

let documentLoads: () => number
let errors: ReturnType<typeof collectErrors>
/**
 * 既存の問題として想定する、ログアウトの通信失敗による未処理の例外・Promise拒否。
 * 想定するのは、ログアウト操作の後に発生した、モックで失敗させたログアウト通信の失敗
 * (印の付いた理由)による Promise拒否1件と、それに対応する pageerror 1件だけ。
 * 値は、ログアウト操作の直前までに記録されていた pageerror・Promise拒否の件数。
 */
let expectedLogoutFailure: { pageErrorsBefore: number; rejectionsBefore: number } | null

test.beforeEach(({ page }) => {
  documentLoads = countDocumentLoads(page)
  errors = collectErrors(page)
  expectedLogoutFailure = null
})

test.afterEach(async ({ page }) => {
  // 操作は同じ文書内で行われ、ハイドレーション関連の出力が無い
  expect(documentLoads()).toBe(1)
  expect(errors.hydrationMessages).toEqual([])

  const pageErrors = [...errors.pageErrors]
  const rejections = await unhandledRejections(page)

  if (expectedLogoutFailure) {
    const { pageErrorsBefore, rejectionsBefore } = expectedLogoutFailure
    // ログアウト操作の前には、どちらも発生していない
    expect(pageErrorsBefore).toBe(0)
    expect(rejectionsBefore).toBe(0)

    // ログアウト操作の後に、印の付いた(モックで失敗させたログアウト通信の)拒否が1件
    expect(rejections).toEqual([
      { reason: 'TypeError: Failed to fetch', failedRequest: LOGOUT_REQUEST },
    ])
    rejections.splice(0)

    // それに対応する pageerror が1件
    expect(pageErrors).toEqual([{ name: 'TypeError', message: 'Failed to fetch' }])
    pageErrors.splice(0)
  }

  // 想定したもの以外の未処理の例外・Promise拒否が無い
  expect(pageErrors).toEqual([])
  expect(rejections).toEqual([])
})

/** 投稿一覧を開き、ログイン中の操作欄が表示されるまで待つ */
async function openPostsLoggedIn(page: Page, path = '/posts') {
  await page.goto(path)
  await expect(listHeading(page)).toBeVisible()
  await expect(logoutButton(page)).toBeVisible()
  await expect(guestCta(page)).toHaveCount(0)
}

test.describe('直接アクセス', () => {
  test.describe('トークンなし', () => {
    test.use({ token: null })

    test('未ログインの操作欄を表示する', async ({ page, api }) => {
      await page.goto('/posts')

      await expect(listHeading(page)).toBeVisible()
      await expect(page.getByText('エネルギーの投稿')).toBeVisible()
      await expect(guestCta(page)).toHaveAttribute('href', '/login')
      await expect(logoutButton(page)).toHaveCount(0)
      await expect(createLink(page)).toHaveCount(0)

      expect(new Set(api.requests())).toEqual(new Set(['GET /api/posts']))
    })
  })

  test.describe('ダミートークンあり', () => {
    test.use({ token: DUMMY_TOKEN })

    test('ハイドレーション後にログイン中の操作欄へ切り替わる', async ({ page, api }) => {
      // 判定前(サーバー描画・ハイドレーション時)は従来どおり未ログインの操作欄のため、
      // 一時的な未ログイン表示の有無は確認せず、最終的な表示だけを確認する
      await openPostsLoggedIn(page)

      await expect(createLink(page)).toHaveAttribute('href', '/posts/create')
      await expect(page.getByRole('link', { name: '下書き一覧', exact: true })).toBeVisible()

      // トークンの検証APIは呼ばない(/api/me は投稿者の敬称のための取得)
      await expect
        .poll(() => new Set(api.requests()))
        .toEqual(new Set(['GET /api/posts', 'GET /api/me']))
    })
  })
})

test.describe('ログイン後の移動', () => {
  test.use({ token: null })

  test('ログインAPIの成功後に投稿一覧へ移動し、ログイン中の操作欄を表示する', async ({
    page,
    api,
  }) => {
    await page.goto('/login')
    await expect(loginHeading(page)).toBeVisible()

    // パスワード欄のラベルには表示切り替えボタン(aria-label にも「パスワード」を含む)が
    // 含まれるため、入力欄は placeholder で特定する
    await page.getByPlaceholder('you@example.com', { exact: true }).fill('e2e@example.com')
    await page.getByPlaceholder('パスワード', { exact: true }).fill('e2e-password')
    await page.getByRole('button', { name: 'ログインする', exact: true }).click()

    await expect(page).toHaveURL(/\/posts$/)
    await expect(listHeading(page)).toBeVisible()
    await expect(logoutButton(page)).toBeVisible()
    await expect(createLink(page)).toBeVisible()
    await expect(guestCta(page)).toHaveCount(0)
    expect(await storedToken(page)).toBe(LOGIN_TOKEN)

    // クライアント側の移動ではハイドレーションを伴わないため、投稿一覧で
    // 未ログインの操作欄が一度も挿入されていない
    expect(await guestCtaInsertions(page)).toEqual([])

    expect(api.requests()).toEqual(
      expect.arrayContaining(['GET /sanctum/csrf-cookie', 'POST /api/login', 'GET /api/posts']),
    )
  })
})

test.describe('ログアウト', () => {
  test.use({ token: DUMMY_TOKEN })

  test('成功するとトークンを削除して /login へ移動し、投稿一覧で未ログインの操作欄への切り替えが記録される', async ({
    page,
    api,
  }) => {
    await openPostsLoggedIn(page)
    const before = (await guestCtaInsertions(page)).length

    await logoutButton(page).click()

    await expect(page).toHaveURL(/\/login$/)
    await expect(loginHeading(page)).toBeVisible()
    expect(await storedToken(page)).toBeNull()
    expect(api.logoutAuthorizations()).toEqual([`Bearer ${DUMMY_TOKEN}`])

    // ログアウト操作の後、未ログインの操作欄が挿入され、その記録時点の pathname が /posts。
    // 通知が無い場合は /login への移動とトークン削除だけが起こり、ここが空になる想定。
    // pathname はコールバック実行時の値で、更新と移動の順番の厳密な証明ではない。
    expect((await guestCtaInsertions(page)).slice(before)).toContain('/posts')
  })

  test('タグで絞り込み中でも、ログアウトできる', async ({ page, api }) => {
    await openPostsLoggedIn(page, `/posts?tag=${ENERGY.slug}`)
    await expect(filterLabel(page)).toHaveText(`#${ENERGY.name}`)
    await expect(createLink(page)).toHaveAttribute('href', '/posts/create')
    const before = (await guestCtaInsertions(page)).length

    await logoutButton(page).click()

    await expect(page).toHaveURL(/\/login$/)
    expect(await storedToken(page)).toBeNull()
    expect((await guestCtaInsertions(page)).slice(before)).toContain('/posts')
    expect(api.requests()).toContain(`GET /api/posts?tag=${ENERGY.slug}`)
  })

  test.describe('タグで絞り込み中・トークンなし', () => {
    test.use({ token: null })

    test('未ログインの操作欄から /login へ移動できる', async ({ page }) => {
      await page.goto(`/posts?tag=${ENERGY.slug}`)
      await expect(filterLabel(page)).toHaveText(`#${ENERGY.name}`)

      await guestCta(page).click()

      await expect(page).toHaveURL(/\/login$/)
      await expect(loginHeading(page)).toBeVisible()
    })
  })
})

// 既存の問題(apiLogout 本体の動作)を、現状の動作として記録する。
// ここでの期待値は望ましい仕様ではなく、既存の挙動の記録である。
// 今回の修正の対象外で、apiLogout を修正する際に期待値を見直す。
test.describe('既存の問題: ログアウトAPIの失敗(既存の挙動の記録)', () => {
  test.use({ token: DUMMY_TOKEN })

  test.describe('通信失敗', () => {
    test.use({ logoutMock: 'network-error' })

    test('トークンを残して投稿一覧に留まり、未処理のPromise拒否が1件発生する', async ({
      page,
      api,
    }) => {
      await openPostsLoggedIn(page)

      expectedLogoutFailure = {
        pageErrorsBefore: errors.pageErrors.length,
        rejectionsBefore: (await unhandledRejections(page)).length,
      }
      await logoutButton(page).click()

      await expect.poll(() => api.logoutAuthorizations()).toHaveLength(1)
      await expect.poll(async () => (await unhandledRejections(page)).length).toBe(1)

      expect(new URL(page.url()).pathname).toBe('/posts')
      await expect(logoutButton(page)).toBeVisible()
      expect(await storedToken(page)).toBe(DUMMY_TOKEN)
      // 失敗の詳細(印の付いた理由・pageerror)は afterEach で照合する
    })
  })

  test.describe('HTTPエラー(500)', () => {
    test.use({ logoutMock: 'server-error' })

    // 望ましい仕様ではない(サーバーでトークンが失効したか分からないまま削除している)
    test('成功時と同じくトークンを削除して /login へ移動する(既存の挙動)', async ({
      page,
    }) => {
      await openPostsLoggedIn(page)
      await logoutButton(page).click()

      await expect(page).toHaveURL(/\/login$/)
      expect(await storedToken(page)).toBeNull()
    })
  })
})
