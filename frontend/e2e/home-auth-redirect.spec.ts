// frontend/e2e/home-auth-redirect.spec.ts
//
// トップページ(/)の、ログイン用トークンの有無による表示・移動を確認する回帰テスト。
//
// - トップページはトークンの「存在」だけを見る(有効性はAPIで確認しない)。
//   ここでもダミーのトークンを localStorage に置くだけで、認証APIは呼ばれない前提とする。
// - バックエンド(http://localhost:8000)への通信はモックし、実バックエンド・DBは使わない。
//   モック対象外のバックエンド通信と、フロントエンド・バックエンド以外への通信は
//   遮断・記録し、テスト終了時に1件も無いことを確認する。
// - フロントエンド(baseURL)への通信は、Next.jsの資産・RSC・プリフェッチ等として許可する。
//   アプリのAPI通信はバックエンドに対して行うため、フロントエンドの /api/* への通信は
//   想定外として扱う。
// - 紹介ページの見出しがDOMへ挿入されたかを、ページ読み込み前に設置した
//   MutationObserver で調べる。検出範囲と限界は E2E_INIT_SCRIPT のコメントを参照。
// - 直接アクセス(ハイドレーションを伴う)に加え、別画面(/login)の共通ヘッダーにある
//   既存の「OpenPersona」リンクから / へ、クライアント側で移動するケースも確認する。

import { test as base, expect, type Page } from '@playwright/test'

const BACKEND_ORIGIN = 'http://localhost:8000'

/** 紹介ページにだけ含まれる見出しの文字列 */
const LANDING_MARKER = '発信者の顔が見えるSNS'

type HomeE2E = {
  /** 紹介ページの文字列がDOMに追加された回数 */
  landingInsertions: () => number
}

declare global {
  interface Window {
    __homeE2E: HomeE2E
  }
}

type Guard = {
  /** Next.js等として許可したフロントエンドへの通信(参考情報) */
  frontendRequests: () => string[]
  /** モックで応答したバックエンドAPI */
  apiRequests: () => string[]
}

/**
 * ページの読み込み前(アプリのスクリプトより前)に実行する初期化スクリプト。
 *
 * - token が文字列ならダミートークンを置き、null なら削除する。
 * - document 全体を MutationObserver で監視し、追加されたノードと変更された
 *   テキストに LANDING_MARKER が含まれていれば数える。追加後に削除されたノードも、
 *   記録(addedNodes)に残っているため数えられる。
 *
 * 検出範囲と限界:
 * - 検出するのは「この文書のDOMに、紹介ページの見出しの文字列を含むノードが
 *   追加された(またはテキストがその文字列に変わった)こと」だけで、紹介ページ全体が
 *   一瞬も描画されなかったことの保証ではない。見出し以外の部分だけの挿入は検出しない。
 *   画面への描画(ペイント)も確認しない。
 * - ノードの内容は、記録を受け取った時点の値で調べる。同じバッチの中で追加された後に
 *   内容が書き換えられた場合は、追加時の内容を見逃しうる。
 * - ページ全体の再読み込みで文書が替わると、それまでの記録は失われる
 *   (テスト側で文書の読み込みが1回だけであることを確認する)。
 */
function E2E_INIT_SCRIPT({
  token,
  marker,
}: {
  token: string | null
  marker: string
}) {
  if (token === null) {
    localStorage.removeItem('openpersona_token')
  } else {
    localStorage.setItem('openpersona_token', token)
  }

  let insertions = 0

  const containsMarker = (node: Node) =>
    (node.textContent ?? '').includes(marker)

  new MutationObserver((records) => {
    for (const record of records) {
      if (record.type === 'characterData' && containsMarker(record.target)) {
        insertions += 1
      }

      for (const node of Array.from(record.addedNodes)) {
        if (containsMarker(node)) {
          insertions += 1
        }
      }
    }
  }).observe(document, { childList: true, subtree: true, characterData: true })

  window.__homeE2E = {
    landingInsertions: () => insertions,
  }
}

// 実際のAPI(PostController::index)と同じ応答形式
const EMPTY_POSTS_RESPONSE = {
  posts: [],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
}

const test = base.extend<{
  token: string | null
  guard: Guard
}>({
  token: [null, { option: true }],
  // 第2引数は慣例では use だが、ReactのHook(use)と誤認されないよう別名にする
  guard: async ({ page, baseURL, token }, provide) => {
    if (!baseURL) {
      throw new Error('playwright.config.ts の baseURL が未設定です')
    }

    const frontendOrigin = new URL(baseURL).origin
    const corsHeaders = { 'Access-Control-Allow-Origin': frontendOrigin }
    const unexpected: string[] = []
    const frontendRequests: string[] = []
    const apiRequests: string[] = []

    // フロントエンド・バックエンド以外への通信は遮断して記録する
    // (後から登録したrouteが優先されるため、バックエンド用はこの後に登録する)
    await page.route('**/*', async (route) => {
      const request = route.request()
      const url = new URL(request.url())

      if (url.origin === frontendOrigin && !url.pathname.startsWith('/api/')) {
        frontendRequests.push(`${request.method()} ${url.pathname}${url.search}`)
        await route.fallback()
        return
      }

      unexpected.push(`${request.method()} ${url.href}`)
      await route.abort()
    })

    await page.route(
      (url) => url.origin === BACKEND_ORIGIN,
      async (route) => {
        const request = route.request()
        const url = new URL(request.url())
        const label = `${request.method()} ${url.pathname}${url.search}`

        // 移動先の投稿一覧(/posts)が表示時に取得する一覧
        if (request.method() === 'GET' && url.pathname === '/api/posts') {
          apiRequests.push(label)
          await route.fulfill({
            status: 200,
            headers: corsHeaders,
            contentType: 'application/json',
            body: JSON.stringify(EMPTY_POSTS_RESPONSE),
          })
          return
        }

        unexpected.push(`${request.method()} ${url.href}`)
        await route.abort()
      },
    )

    await page.addInitScript(E2E_INIT_SCRIPT, { token, marker: LANDING_MARKER })

    await provide({
      frontendRequests: () => [...frontendRequests],
      apiRequests: () => [...apiRequests],
    })

    expect(unexpected, '想定外の通信がありました').toEqual([])
  },
})

/** ハイドレーション関連のコンソール出力と、ページ内の未捕捉例外を集める */
function collectErrors(page: Page) {
  const hydrationMessages: string[] = []
  const pageErrors: string[] = []

  page.on('console', (message) => {
    if (
      (message.type() === 'error' || message.type() === 'warning') &&
      /hydrat|did not match|didn't match/i.test(message.text())
    ) {
      hydrationMessages.push(`[${message.type()}] ${message.text()}`)
    }
  })

  page.on('pageerror', (error) => {
    pageErrors.push(error.message)
  })

  return { hydrationMessages, pageErrors }
}

/** 文書の読み込み(ページ全体の読み込み)の回数を数える */
function countDocumentLoads(page: Page) {
  let loads = 0
  page.on('domcontentloaded', () => {
    loads += 1
  })
  return () => loads
}

async function landingInsertions(page: Page) {
  return page.evaluate(() => window.__homeE2E.landingInsertions())
}

test.describe('トークンなし', () => {
  test.use({ token: null })

  test('紹介ページが表示され、URLは / のまま', async ({ page, guard }) => {
    const errors = collectErrors(page)

    await page.goto('/')

    await expect(
      page.getByRole('heading', { level: 1, name: LANDING_MARKER }),
    ).toBeVisible()
    await expect(page.getByRole('link', { name: 'ログイン' })).toBeVisible()
    await expect(page.getByText('読み込み中...')).toHaveCount(0)
    expect(new URL(page.url()).pathname).toBe('/')

    // 検出の仕組みが働いていることの確認(紹介ページの挿入を検出できている)
    expect(await landingInsertions(page)).toBeGreaterThan(0)

    // トップページはバックエンドAPIを呼ばない
    expect(guard.apiRequests()).toEqual([])

    expect(errors.hydrationMessages).toEqual([])
    expect(errors.pageErrors).toEqual([])
  })
})

test.describe('ダミートークンあり', () => {
  test.use({ token: 'e2e-dummy-token' })

  test('紹介ページを表示せずに /posts へ移動する', async ({ page, guard }) => {
    const errors = collectErrors(page)

    // 紹介ページの検出は同じ文書内でしか働かないため、移動がページ全体の
    // 再読み込みではなく、同じ文書内(クライアント側)で行われたことも確認する
    const documentLoads = countDocumentLoads(page)

    await page.goto('/')

    await expect(page).toHaveURL(/\/posts$/)
    await expect(
      page.getByRole('heading', { level: 1, name: '投稿一覧' }),
    ).toBeVisible()
    await expect(page.getByText('まだ投稿がありません。')).toBeVisible()

    // 読み込みから移動後の表示までの間に、紹介ページの見出しがDOMへ一度も挿入されていない
    expect(documentLoads()).toBe(1)
    expect(await landingInsertions(page)).toBe(0)

    // バックエンドへの通信は移動先の投稿一覧の取得だけで、トークンの検証APIは呼ばない
    // (開発時のStrict Modeでは一覧の取得が2回行われうるため、回数は固定しない)
    const apiRequests = guard.apiRequests()
    expect(apiRequests.length).toBeGreaterThan(0)
    expect(new Set(apiRequests)).toEqual(new Set(['GET /api/posts']))

    expect(errors.hydrationMessages).toEqual([])
    expect(errors.pageErrors).toEqual([])
  })
})

test.describe('別画面からクライアント側で / へ移動', () => {
  test.describe('トークンなし', () => {
    test.use({ token: null })

    test('/login のヘッダーのリンクから移動すると紹介ページが表示される', async ({
      page,
      guard,
    }) => {
      const errors = collectErrors(page)
      const documentLoads = countDocumentLoads(page)

      await page.goto('/login')
      await expect(page.getByRole('heading', { level: 1, name: 'ログイン' })).toBeVisible()
      expect(await landingInsertions(page)).toBe(0)

      await page.getByRole('link', { name: 'OpenPersona', exact: true }).click()

      await expect(page).toHaveURL(/\/$/)
      await expect(
        page.getByRole('heading', { level: 1, name: LANDING_MARKER }),
      ).toBeVisible()
      // 一時的な読み込み表示は省略されうるが、最終的には表示されていない
      await expect(page.getByText('読み込み中...')).toHaveCount(0)

      // クライアント側の移動であり、紹介ページの挿入を検出できている
      expect(documentLoads()).toBe(1)
      expect(await landingInsertions(page)).toBeGreaterThan(0)

      expect(guard.apiRequests()).toEqual([])
      expect(errors.hydrationMessages).toEqual([])
      expect(errors.pageErrors).toEqual([])
    })
  })

  test.describe('ダミートークンあり', () => {
    test.use({ token: 'e2e-dummy-token' })

    test('/login のヘッダーのリンクから移動すると、紹介ページを表示せずに /posts へ移動する', async ({
      page,
      guard,
    }) => {
      const errors = collectErrors(page)
      const documentLoads = countDocumentLoads(page)

      await page.goto('/login')
      await expect(page.getByRole('heading', { level: 1, name: 'ログイン' })).toBeVisible()

      await page.getByRole('link', { name: 'OpenPersona', exact: true }).click()

      await expect(page).toHaveURL(/\/posts$/)
      await expect(
        page.getByRole('heading', { level: 1, name: '投稿一覧' }),
      ).toBeVisible()

      expect(documentLoads()).toBe(1)
      expect(await landingInsertions(page)).toBe(0)

      // / は router.replace で置き換えられるため、戻ると /login に戻る
      await page.goBack()
      await expect(page).toHaveURL(/\/login$/)
      await expect(page.getByRole('heading', { level: 1, name: 'ログイン' })).toBeVisible()

      const apiRequests = guard.apiRequests()
      expect(apiRequests.length).toBeGreaterThan(0)
      expect(new Set(apiRequests)).toEqual(new Set(['GET /api/posts']))
      expect(errors.hydrationMessages).toEqual([])
      expect(errors.pageErrors).toEqual([])
    })
  })
})
