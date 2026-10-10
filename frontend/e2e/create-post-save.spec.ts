// frontend/e2e/create-post-save.spec.ts
//
// 投稿作成画面(/posts/create)の保存結果の分類・二重送信の防止・再操作の回帰テスト。
//
// - バックエンド(http://localhost:8000)への通信はモックし、実バックエンド・DBは使わない。
//   モック対象外のバックエンド通信と、フロントエンド・バックエンド以外への通信は
//   遮断・記録し、テスト終了時に1件も無いことを確認する。
//   フロントエンド(baseURL)への通信は、Next.jsの資産・RSC・プリフェッチ等として許可する。
// - 投稿作成(POST /api/posts)と添付送信(POST /api/posts/{id}/attachments)は、
//   ルートで保留し、テストが応答を決めるまで返さない。送信回数・送信内容はルートで記録する。
// - 応答後の画面の切り替えは再試行付きアサーションで確認し、送信回数が増えないことや
//   表示が維持されることは、有限の観測時間(OBSERVATION_MS)のあいだ変化しないことで確認する。
//   観測時間以降も変化しないことの保証ではない。
// - 未処理のPromise拒否は、ページのスクリプトより前に設置した unhandledrejection の
//   記録と pageerror で確認する。
//
// 二重送信の防止について:
// - 連打は、ページ内で同じ処理の中から button.click() を2回続けて呼んで再現する。
//   1回目のクリックによる state 更新(ボタンの無効化)は、2回目の呼び出しの後に描画される
//   想定のため、2回目もボタンが有効なまま処理が呼ばれ、同期的なガードで止める必要がある。
//   無効化されたボタンへの2回目のクリックを待ち続ける構成にはしない。
// - ただし、描画のタイミングによってはボタンの無効化だけで防げる可能性もある。ガード(ref)を
//   外した改変版でこのテストが失敗するかは、実行段階で確認する(必ず失敗するとは前提にしない)。

import { test as base, expect, type Page, type Route } from '@playwright/test'

const BACKEND_ORIGIN = 'http://localhost:8000'
const TOKEN_KEY = 'openpersona_token'
const DUMMY_TOKEN = 'e2e-dummy-token'

const OBSERVATION_MS = 1_000
const OBSERVATION_INTERVAL_MS = 50

const CREATED_POST_ID = 42

type MockResponse =
  | { kind: 'json'; status: number; body: unknown }
  | { kind: 'raw'; status: number; body: string; contentType?: string }
  | { kind: 'network-error' }

type Held<T> = T & {
  released: boolean
  respond: (response: MockResponse) => void
}

type PostRequest = Held<{ body: Record<string, unknown> }>
type UploadRequest = Held<{ postId: string; fileNames: string[] }>

type Api = {
  /** 到着した POST /api/posts(到着順) */
  posts: () => PostRequest[]
  /** 到着した POST /api/posts/{id}/attachments(到着順) */
  uploads: () => UploadRequest[]
  /** POST /api/posts が count 件以上到着するまで待つ(届かなければテストのタイムアウトで失敗する) */
  waitForPosts: (count: number) => Promise<PostRequest[]>
  /** 添付送信が count 件以上到着するまで待つ */
  waitForUploads: (count: number) => Promise<UploadRequest[]>
}

declare global {
  interface Window {
    __createE2E: {
      unhandledRejections: () => string[]
    }
  }
}

// 実際のAPIと同じ応答形式
// - GET /api/categories: CategoryController::index({ categories })
// - POST /api/posts: PostController::store({ message, post }, 201)
// - POST /api/posts/{post}/attachments: PostAttachmentController::store({ message, attachments }, 201)
// - 失敗: Laravel の JSON エラー応答({ message, errors? })、PostAttachmentController の 500
const CATEGORIES = [
  { id: 1, name: '政治', slug: 'politics', posting_age_limit: null, sort_order: 1 },
  { id: 2, name: '経済', slug: 'economy', posting_age_limit: null, sort_order: 2 },
]

const createdPost = (status: 'draft' | 'published', id = CREATED_POST_ID) => ({
  user_id: 1,
  category_id: 1,
  title: '投稿のタイトル',
  body: '投稿の本文',
  status,
  published_at: status === 'published' ? '2026-10-08T00:00:00.000000Z' : null,
  updated_at: '2026-10-08T00:00:00.000000Z',
  created_at: '2026-10-08T00:00:00.000000Z',
  id,
  sources: [],
  tags: [],
})

const postCreated = (status: 'draft' | 'published'): MockResponse => ({
  kind: 'json',
  status: 201,
  body: {
    message: status === 'published' ? '投稿を公開しました。' : '下書きを保存しました。',
    post: createdPost(status),
  },
})

const attachmentItem = (id: number, fileName: string) => ({
  id,
  file_name: fileName,
  file_type: 'image',
  file_size: 1024,
  url: `http://localhost:8000/storage/attachments/${CREATED_POST_ID}/${id}.png`,
  created_at: '2026-10-08T00:00:00.000000Z',
})

const attachmentsSaved = (fileNames: string[]): MockResponse => ({
  kind: 'json',
  status: 201,
  body: {
    message: '添付ファイルをアップロードしました。',
    attachments: fileNames.map((name, index) => attachmentItem(index + 1, name)),
  },
})

const validationError = (message: string): MockResponse => ({
  kind: 'json',
  status: 422,
  body: { message, errors: { files: [message] } },
})

/** 小さなPNG(1x1)。画像として選択できればよく、内容は検証しない */
const PNG_BYTES = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64',
)

const pngFile = (name: string) => ({ name, mimeType: 'image/png', buffer: PNG_BYTES })

/** multipart の本文から、files[] のファイル名を順に取り出す */
function fileNamesInMultipart(body: Buffer | null): string[] {
  if (!body) {
    return []
  }

  const text = body.toString('latin1')
  const names: string[] = []
  const pattern = /name="files\[\]"; filename="([^"]*)"/g
  let match: RegExpExecArray | null

  while ((match = pattern.exec(text)) !== null) {
    names.push(Buffer.from(match[1], 'latin1').toString('utf8'))
  }

  return names
}

/**
 * ページの読み込み前(アプリのスクリプトより前)に実行する初期化スクリプト。
 * - ダミートークンを置く(文書の読み込みごとに実行されるため、読み込みが1回だけであることを確認する)。
 * - 未処理のPromise拒否(unhandledrejection)を記録する。
 */
function E2E_INIT_SCRIPT({ token, tokenKey }: { token: string; tokenKey: string }) {
  localStorage.setItem(tokenKey, token)

  const unhandledRejections: string[] = []
  window.addEventListener('unhandledrejection', (event) => {
    unhandledRejections.push(String(event.reason))
  })

  window.__createE2E = {
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
      const corsHeaders = { 'Access-Control-Allow-Origin': frontendOrigin }
      const unexpected: string[] = []
      const posts: PostRequest[] = []
      const uploads: UploadRequest[] = []
      const arrivalWaiters: Array<() => void> = []

      const notifyArrival = () => {
        for (const notify of arrivalWaiters.splice(0)) {
          notify()
        }
      }

      const waitUntil = async (condition: () => boolean) => {
        while (!condition()) {
          await new Promise<void>((resolve) => arrivalWaiters.push(resolve))
        }
      }

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
          contentType:
            response.kind === 'raw'
              ? (response.contentType ?? 'application/json')
              : 'application/json',
          body:
            response.kind === 'json' ? JSON.stringify(response.body) : response.body,
        })
      }

      /** 応答を保留する要素を作り、テストが respond を呼ぶまでルートを待たせる */
      const hold = <T extends object>(fields: T) => {
        let resolveResponse!: (response: MockResponse) => void
        const responsePromise = new Promise<MockResponse>((resolve) => {
          resolveResponse = resolve
        })
        const item = {
          ...fields,
          released: false,
          respond: (response: MockResponse) => {
            if (item.released) {
              return
            }
            item.released = true
            resolveResponse(response)
          },
        } as Held<T>
        return { item, responsePromise }
      }

      await page.route(
        (url) => url.origin === BACKEND_ORIGIN,
        async (route) => {
          const request = route.request()
          const url = new URL(request.url())
          const method = request.method()

          if (method === 'GET' && url.pathname === '/api/categories') {
            await fulfill(route, {
              kind: 'json',
              status: 200,
              body: { categories: CATEGORIES },
            })
            return
          }

          if (method === 'POST' && url.pathname === '/api/posts') {
            const { item, responsePromise } = hold({
              body: request.postDataJSON() as Record<string, unknown>,
            })
            posts.push(item)
            notifyArrival()
            await fulfill(route, await responsePromise)
            return
          }

          const uploadMatch = url.pathname.match(/^\/api\/posts\/(\d+)\/attachments$/)
          if (method === 'POST' && uploadMatch) {
            const { item, responsePromise } = hold({
              postId: uploadMatch[1],
              fileNames: fileNamesInMultipart(request.postDataBuffer()),
            })
            uploads.push(item)
            notifyArrival()
            await fulfill(route, await responsePromise)
            return
          }

          // 完了後の移動先(下書き一覧・投稿一覧)。空一覧のため /api/me は呼ばれない
          if (method === 'GET' && url.pathname === '/api/posts/drafts') {
            await fulfill(route, { kind: 'json', status: 200, body: { posts: [] } })
            return
          }

          if (method === 'GET' && url.pathname === '/api/posts') {
            await fulfill(route, {
              kind: 'json',
              status: 200,
              body: {
                posts: [],
                meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
              },
            })
            return
          }

          unexpected.push(`${method} ${url.href}`)
          await route.abort()
        },
      )

      await page.addInitScript(E2E_INIT_SCRIPT, { token: DUMMY_TOKEN, tokenKey: TOKEN_KEY })

      await provide({
        posts: () => [...posts],
        uploads: () => [...uploads],
        waitForPosts: async (count) => {
          await waitUntil(() => posts.length >= count)
          return [...posts]
        },
        waitForUploads: async (count) => {
          await waitUntil(() => uploads.length >= count)
          return [...uploads]
        },
      })

      for (const item of [...posts, ...uploads]) {
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

/**
 * check が OBSERVATION_MS のあいだ成り立ち続けることを確認する。
 * check の中では再試行付きアサーションを使わず、その時点の値を1回だけ読んで判定する。
 * 有限時間の観測であり、それ以降も変化しないことを保証するものではない。
 */
async function expectStable(page: Page, check: () => Promise<void> | void) {
  const deadline = Date.now() + OBSERVATION_MS

  while (Date.now() < deadline) {
    await check()
    await page.waitForTimeout(OBSERVATION_INTERVAL_MS)
  }

  await check()
}

const button = (page: Page, name: string) =>
  page.getByRole('button', { name, exact: true })
const titleInput = (page: Page) =>
  page.getByRole('textbox', { name: 'タイトル', exact: true }).first()
const bodyInput = (page: Page) => page.getByRole('textbox', { name: '本文', exact: true })
const categorySelect = (page: Page) =>
  page.getByRole('combobox', { name: 'カテゴリ', exact: true })
const fileInput = (page: Page) => page.locator('input[type="file"]')
const status = (page: Page, text: string) =>
  page.getByRole('status').filter({ hasText: text })
const confirmLink = (page: Page, name: string) =>
  page.getByRole('link', { name: `${name}（新しいタブ）`, exact: true })

/**
 * ページ内で同じ処理の中から、指定したボタンの click() を2回続けて呼ぶ。
 * 1回目の state 更新が描画される前に2回目の操作が重なる条件を作る。
 */
async function clickTwiceInSameTask(page: Page, name: string) {
  await page.evaluate((label) => {
    const target = Array.from(document.querySelectorAll('button')).find(
      (element) => element.textContent?.trim() === label,
    )

    if (!target) {
      throw new Error(`ボタンが見つかりません: ${label}`)
    }

    target.click()
    target.click()
  }, name)
}

/** 作成画面を開き、カテゴリの読み込み完了(選択可能)まで待つ */
async function openCreate(page: Page) {
  await page.goto('/posts/create')
  await expect(page.getByRole('heading', { level: 1, name: '投稿作成' })).toBeVisible()
  await expect(categorySelect(page)).toBeEnabled()
}

/** 投稿の内容(カテゴリ・タイトル・本文・出典・タグ)が変更できない */
async function expectPostContentLocked(page: Page) {
  await expect(categorySelect(page)).toBeDisabled()
  await expect(titleInput(page)).not.toBeEditable()
  await expect(bodyInput(page)).not.toBeEditable()
  await expect(button(page, 'ソースを追加')).toBeDisabled()
  await expect(page.getByPlaceholder('例: エネルギー政策')).toBeDisabled()
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
  expect(await page.evaluate(() => window.__createE2E.unhandledRejections())).toEqual([])
})

test.describe('添付なしの保存', () => {
  for (const [label, postStatus, path] of [
    ['下書き保存', 'draft', /\/posts\/drafts$/],
    ['公開する', 'published', /\/posts$/],
  ] as const) {
    test(`${label}は投稿作成1回で、既存の遷移先へ移動する`, async ({ page, api }) => {
      await openCreate(page)
      await button(page, label).click()

      const [request] = await api.waitForPosts(1)
      expect(request.body).toMatchObject({ status: postStatus, category_id: 1 })
      request.respond(postCreated(postStatus))

      await expect(page).toHaveURL(path)
      expect(api.posts()).toHaveLength(1)
      expect(api.uploads()).toHaveLength(0)
    })
  }
})

test.describe('投稿作成の二重送信', () => {
  test('送信中に連続して押しても、投稿作成は1回だけ', async ({ page, api }) => {
    await openCreate(page)

    await clickTwiceInSameTask(page, '下書き保存')
    await api.waitForPosts(1)

    await expect(button(page, '下書き保存')).toBeDisabled()
    await expect(button(page, '公開する')).toBeDisabled()
    await expect(status(page, '下書き保存中...')).toBeVisible()
    await expectStable(page, () => {
      expect(api.posts()).toHaveLength(1)
    })

    api.posts()[0].respond(postCreated('draft'))
    await expect(page).toHaveURL(/\/posts\/drafts$/)
    expect(api.posts()).toHaveLength(1)
  })
})

test.describe('投稿作成の確定した失敗(未保存)', () => {
  test('422 では入力を直して再送できる', async ({ page, api }) => {
    await openCreate(page)
    await button(page, '下書き保存').click()

    const [first] = await api.waitForPosts(1)
    first.respond({
      kind: 'json',
      status: 422,
      body: {
        message: 'タイトルは255文字以内にしてください。',
        errors: { title: ['タイトルは255文字以内にしてください。'] },
      },
    })

    await expect(status(page, 'タイトルは255文字以内にしてください。')).toBeVisible()
    await expect(titleInput(page)).toBeEditable()
    await expect(button(page, '下書き保存')).toBeEnabled()

    await titleInput(page).fill('直したタイトル')
    await button(page, '下書き保存').click()

    const [, second] = await api.waitForPosts(2)
    expect(second.body).toMatchObject({ title: '直したタイトル', status: 'draft' })
    second.respond(postCreated('draft'))

    await expect(page).toHaveURL(/\/posts\/drafts$/)
    expect(api.posts()).toHaveLength(2)
  })
})

test.describe('投稿作成の結果不明', () => {
  const unknownResponses: Array<[string, MockResponse]> = [
    ['500', { kind: 'json', status: 500, body: { message: 'Server Error' } }],
    ['通信失敗', { kind: 'network-error' }],
    ['不正なJSON', { kind: 'raw', status: 201, body: '<html>not json</html>', contentType: 'text/html' }],
    ['投稿IDの無い成功応答', { kind: 'json', status: 201, body: { message: '下書きを保存しました。', post: {} } }],
    ['要求と異なる状態の成功応答', postCreated('published')],
  ]

  for (const [label, response] of unknownResponses) {
    test(`${label}では結果不明として、再送できず一覧での確認を案内する`, async ({
      page,
      api,
    }) => {
      await openCreate(page)
      await button(page, '下書き保存').click()

      const [request] = await api.waitForPosts(1)
      request.respond(response)

      await expect(status(page, '保存結果を確認できませんでした。')).toBeVisible()
      await expectPostContentLocked(page)
      await expect(button(page, '下書き保存')).toBeDisabled()
      await expect(button(page, '公開する')).toBeDisabled()
      await expect(button(page, 'ファイルを選択')).toBeDisabled()

      // 入力内容は確認・コピーできる(読み取り専用で表示されている)
      await expect(titleInput(page)).toHaveValue('日本のエネルギー政策について')

      for (const [name, href] of [
        ['下書き一覧で確認する', '/posts/drafts'],
        ['投稿一覧で確認する', '/posts'],
      ] as const) {
        await expect(confirmLink(page, name)).toHaveAttribute('href', href)
        await expect(confirmLink(page, name)).toHaveAttribute('target', '_blank')
      }

      await expectStable(page, () => {
        expect(api.posts()).toHaveLength(1)
        expect(api.uploads()).toHaveLength(0)
        expect(new URL(page.url()).pathname).toBe('/posts/create')
      })
    })
  }
})

test.describe('投稿と添付の成功', () => {
  test('投稿作成1回・添付一括送信1回で完了する', async ({ page, api }) => {
    await openCreate(page)
    await fileInput(page).setInputFiles([pngFile('a.png'), pngFile('b.png')])
    await button(page, '公開する').click()

    const [post] = await api.waitForPosts(1)
    post.respond(postCreated('published'))

    const [upload] = await api.waitForUploads(1)
    expect(upload.postId).toBe(String(CREATED_POST_ID))
    expect(upload.fileNames).toEqual(['a.png', 'b.png'])
    await expect(status(page, '投稿を公開しました。添付ファイルを送信中...')).toBeVisible()
    upload.respond(attachmentsSaved(['a.png', 'b.png']))

    await expect(page).toHaveURL(/\/posts$/)
    expect(api.posts()).toHaveLength(1)
    expect(api.uploads()).toHaveLength(1)
  })
})

test.describe('添付の確定した失敗(未保存)', () => {
  for (const [postStatus, label, savedLabel, path] of [
    ['draft', '下書き保存', '下書きとして保存', /\/posts\/drafts$/],
    ['published', '公開する', '公開', /\/posts$/],
  ] as const) {
    test(`${label}: 保存済みの状態を表示し、同じ投稿IDへ再送できる。投稿作成は増えない`, async ({
      page,
      api,
    }) => {
      await openCreate(page)
      await fileInput(page).setInputFiles([pngFile('a.png')])
      await button(page, label).click()

      ;(await api.waitForPosts(1))[0].respond(postCreated(postStatus))
      ;(await api.waitForUploads(1))[0].respond(
        validationError('jpg / png / gif / webp / pdf のみアップロードできます。'),
      )

      await expect(
        status(page, `投稿は${savedLabel}されました。添付ファイルは保存されていません`),
      ).toBeVisible()
      await expectPostContentLocked(page)
      await expect(button(page, '公開する')).toHaveCount(0)
      await expect(button(page, '下書き保存')).toHaveCount(0)
      await expect(confirmLink(page, '保存済みの投稿を確認する')).toHaveAttribute(
        'href',
        `/posts/${CREATED_POST_ID}`,
      )
      await expect(confirmLink(page, '保存済みの投稿を確認する')).toHaveAttribute(
        'target',
        '_blank',
      )

      await button(page, '添付ファイルを再アップロード').click()
      const [, retry] = await api.waitForUploads(2)
      expect(retry.postId).toBe(String(CREATED_POST_ID))
      expect(retry.fileNames).toEqual(['a.png'])
      retry.respond(attachmentsSaved(['a.png']))

      await expect(page).toHaveURL(path)
      expect(api.posts()).toHaveLength(1)
      expect(api.uploads()).toHaveLength(2)
    })
  }

  test('待機ファイルを変更して再送すると、現在の待機ファイルだけを一括送信する', async ({
    page,
    api,
  }) => {
    await openCreate(page)
    await fileInput(page).setInputFiles([pngFile('a.png'), pngFile('b.png')])
    await button(page, '下書き保存').click()

    ;(await api.waitForPosts(1))[0].respond(postCreated('draft'))
    ;(await api.waitForUploads(1))[0].respond(validationError('The files.0 failed to upload.'))
    await expect(status(page, '添付ファイルは保存されていません')).toBeVisible()

    // a.png を外し、c.png を追加する
    await button(page, '削除').first().click()
    await fileInput(page).setInputFiles([pngFile('c.png')])

    await button(page, '添付ファイルを再アップロード').click()
    const [, retry] = await api.waitForUploads(2)
    expect(retry.fileNames).toEqual(['b.png', 'c.png'])
    retry.respond(attachmentsSaved(['b.png', 'c.png']))

    await expect(page).toHaveURL(/\/posts\/drafts$/)
    expect(api.posts()).toHaveLength(1)
  })

  test('「添付せずに進む」は既存の遷移先へ移動し、送信しない', async ({ page, api }) => {
    await openCreate(page)
    await fileInput(page).setInputFiles([pngFile('a.png')])
    await button(page, '下書き保存').click()

    ;(await api.waitForPosts(1))[0].respond(postCreated('draft'))
    ;(await api.waitForUploads(1))[0].respond(validationError('The files.0 failed to upload.'))

    await button(page, '添付せずに進む').click()
    await expect(page).toHaveURL(/\/posts\/drafts$/)
    expect(api.posts()).toHaveLength(1)
    expect(api.uploads()).toHaveLength(1)
  })

  test('再送中に連続して押しても、添付送信は1回だけ増える', async ({ page, api }) => {
    await openCreate(page)
    await fileInput(page).setInputFiles([pngFile('a.png')])
    await button(page, '下書き保存').click()

    ;(await api.waitForPosts(1))[0].respond(postCreated('draft'))
    ;(await api.waitForUploads(1))[0].respond(validationError('The files.0 failed to upload.'))
    await expect(button(page, '添付ファイルを再アップロード')).toBeEnabled()

    await clickTwiceInSameTask(page, '添付ファイルを再アップロード')
    await api.waitForUploads(2)

    await expect(status(page, '添付ファイルを送信中...')).toBeVisible()
    await expectStable(page, () => {
      expect(api.uploads()).toHaveLength(2)
    })

    api.uploads()[1].respond(attachmentsSaved(['a.png']))
    await expect(page).toHaveURL(/\/posts\/drafts$/)
    expect(api.uploads()).toHaveLength(2)
    expect(api.posts()).toHaveLength(1)
  })
})

test.describe('添付の結果不明', () => {
  const unknownResponses: Array<[string, MockResponse]> = [
    [
      '500',
      {
        kind: 'json',
        status: 500,
        body: { message: '添付ファイルのアップロードに失敗しました。時間をおいて再度お試しください。' },
      },
    ],
    ['通信失敗', { kind: 'network-error' }],
    // 2xx のため保存済みの可能性がある応答
    [
      '2xxだがJSONを解析できない応答',
      { kind: 'raw', status: 201, body: '<html>created</html>', contentType: 'text/html' },
    ],
    [
      '不正なJSON(HTMLの413)',
      { kind: 'raw', status: 413, body: '<html>413 Request Entity Too Large</html>', contentType: 'text/html' },
    ],
    ['添付一覧の無い成功応答', { kind: 'json', status: 201, body: { message: '添付ファイルをアップロードしました。' } }],
    ['件数が送信数と一致しない成功応答', attachmentsSaved(['a.png'])],
  ]

  for (const [label, response] of unknownResponses) {
    test(`${label}では結果不明として、待機ファイルの変更・再送ができない`, async ({
      page,
      api,
    }) => {
      await openCreate(page)
      await fileInput(page).setInputFiles([pngFile('a.png'), pngFile('b.png')])
      await button(page, '下書き保存').click()

      ;(await api.waitForPosts(1))[0].respond(postCreated('draft'))
      ;(await api.waitForUploads(1))[0].respond(response)

      await expect(
        status(page, '投稿は下書きとして保存されました。添付ファイルが保存されたかを確認できませんでした。'),
      ).toBeVisible()
      // 全部保存されたとは表示しない
      await expect(page).toHaveURL(/\/posts\/create$/)
      await expect(button(page, '添付ファイルを再アップロード')).toHaveCount(0)
      await expect(button(page, 'ファイルを選択')).toBeDisabled()
      // ファイルの選び直し(隠しファイル入力)もできない
      await expect(fileInput(page)).toBeDisabled()
      expect(await button(page, '削除').count()).toBe(2)
      for (const removeButton of await button(page, '削除').all()) {
        await expect(removeButton).toBeDisabled()
      }
      await expectPostContentLocked(page)
      await expect(confirmLink(page, '保存済みの投稿を確認する')).toHaveAttribute(
        'href',
        `/posts/${CREATED_POST_ID}`,
      )

      await expectStable(page, () => {
        expect(api.posts()).toHaveLength(1)
        expect(api.uploads()).toHaveLength(1)
      })

      await button(page, '完了して進む').click()
      await expect(page).toHaveURL(/\/posts\/drafts$/)
      expect(api.uploads()).toHaveLength(1)
    })
  }

  test('公開した投稿では、公開済みとして表示し、完了後は投稿一覧へ移動する', async ({
    page,
    api,
  }) => {
    await openCreate(page)
    await fileInput(page).setInputFiles([pngFile('a.png')])
    await button(page, '公開する').click()

    ;(await api.waitForPosts(1))[0].respond(postCreated('published'))
    ;(await api.waitForUploads(1))[0].respond({ kind: 'network-error' })

    await expect(
      status(page, '投稿は公開されました。添付ファイルが保存されたかを確認できませんでした。'),
    ).toBeVisible()
    await button(page, '完了して進む').click()
    await expect(page).toHaveURL(/\/posts$/)
    expect(api.posts()).toHaveLength(1)
    expect(api.uploads()).toHaveLength(1)
  })
})
