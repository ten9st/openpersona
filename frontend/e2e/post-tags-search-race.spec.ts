// frontend/e2e/post-tags-search-race.spec.ts
//
// タグ検索・タグ作成の応答順が入れ替わっても、古い応答が最新の候補・検索中表示・
// エラー表示を上書きしないことを確認する回帰テスト。
//
// - バックエンド(http://localhost:8000)への通信はすべてモックし、実バックエンド・DBは使わない。
//   対象ページ(/posts/create)はクライアントコンポーネントで、バックエンドへの通信は
//   ブラウザ側のeffect・イベント処理からのみ行われる(Nextサーバー側からは呼ばない)。
// - モック・通信遮断・初期化スクリプトは、ページ遷移の前に設定する。
// - タグAPIへのリクエストは到着した時点で保留し、テスト側が応答を返す順序を決める。
// - フロントエンド以外への通信と、モック対象外のバックエンド通信は遮断・記録し、
//   テスト終了時に1件も無いことを確認する。
// - 表示されることの確認は再試行付きアサーションで行う。
// - 「古い応答が反映されない」ことは、応答を解放してアプリが本文を読み終えたことを
//   確認した後、有限の観測時間(OBSERVATION_MS)のあいだ表示・状態が維持される
//   ことで確認する。これは観測時間内の確認であり、それ以降も変化しないことを
//   保証するものではない。

import { test as base, expect, type Page } from '@playwright/test'

const BACKEND_ORIGIN = 'http://localhost:8000'
const MOCK_ID_HEADER = 'X-Mock-Id'

/** 古い応答による上書きが無いことを観測する時間と間隔 */
const OBSERVATION_MS = 1_000
const OBSERVATION_INTERVAL_MS = 50

const corsHeaders = (frontendOrigin: string) => ({
  'Access-Control-Allow-Origin': frontendOrigin,
  'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
  'Access-Control-Allow-Headers': 'Accept, Authorization, Content-Type',
  'Access-Control-Expose-Headers': MOCK_ID_HEADER,
})

type PostTag = { id: number; name: string; slug: string }

type MockResponse = { status: number; body: unknown }

type HeldRequest = {
  id: string
  method: 'GET' | 'POST'
  /** GET /api/tags?search= の値 */
  keyword: string | null
  /** POST /api/tags の name */
  name: string | null
  released: boolean
  /** 応答を返し、アプリがその応答の本文を読み終える(json()の完了)まで待つ */
  release: (response: MockResponse) => Promise<void>
  /** 応答を返すだけ(後片付け用) */
  respond: (response: MockResponse) => void
}

type TagApi = {
  nextSearch: (keyword?: string) => Promise<HeldRequest>
  nextCreate: () => Promise<HeldRequest>
  searchRequests: () => HeldRequest[]
  createRequests: () => HeldRequest[]
}

type TagFetchCall = { method: string; keyword: string | null }

declare global {
  interface Window {
    __tagsE2E: {
      calls: () => TagFetchCall[]
      whenBodyRead: (id: string) => Promise<void>
    }
  }
}

// 実際のAPI(TagController)と同じ応答形式
const tagsResponse = (tags: PostTag[]): MockResponse => ({
  status: 200,
  body: { tags },
})

const createdResponse = (tag: PostTag): MockResponse => ({
  status: 201,
  body: { tag, created: true },
})

const serverError: MockResponse = {
  status: 500,
  body: { message: 'Server Error' },
}

const tag = (id: number, name: string): PostTag => ({ id, name, slug: name })

const CATEGORIES = [
  {
    id: 1,
    name: '政治',
    slug: 'politics',
    posting_age_limit: null,
    sort_order: 1,
  },
]

/**
 * ページの読み込み前に実行する初期化スクリプト(アプリ本体は変更しない)。
 *
 * - タグAPIへのfetch呼び出しを同期的に記録する(debounce・重複作成の確認用)。
 * - モック応答(X-Mock-Id付き)について、アプリが呼んだ json() の完了
 *   (本文の読み取り。成功・失敗とも)を記録する。観測できるのはこの段階までで、
 *   その後のアプリの状態更新や描画の完了は、ここでは確認しない。
 */
function E2E_INIT_SCRIPT({
  backendOrigin,
  mockIdHeader,
}: {
  backendOrigin: string
  mockIdHeader: string
}) {
  localStorage.setItem('openpersona_token', 'e2e-dummy-token')

  const calls: TagFetchCall[] = []
  const bodyRead = new Set<string>()
  const waiters = new Map<string, Array<() => void>>()

  const markBodyRead = (id: string) => {
    bodyRead.add(id)
    for (const resolve of waiters.get(id) ?? []) {
      resolve()
    }
    waiters.delete(id)
  }

  const originalFetch = window.fetch.bind(window)

  window.fetch = async (input, init) => {
    const url = new URL(
      input instanceof Request ? input.url : String(input),
      window.location.href,
    )
    const method = (
      init?.method ?? (input instanceof Request ? input.method : 'GET')
    ).toUpperCase()
    const isTagApi =
      url.origin === backendOrigin && url.pathname === '/api/tags'

    if (isTagApi) {
      calls.push({ method, keyword: url.searchParams.get('search') })
    }

    const response = await originalFetch(input, init)
    const id = isTagApi ? response.headers.get(mockIdHeader) : null

    if (id) {
      const originalJson = response.json.bind(response)
      response.json = () =>
        originalJson().then(
          (data) => {
            markBodyRead(id)
            return data
          },
          (error) => {
            markBodyRead(id)
            throw error
          },
        )
    }

    return response
  }

  window.__tagsE2E = {
    calls: () => calls.map((call) => ({ ...call })),
    whenBodyRead: (id) =>
      bodyRead.has(id)
        ? Promise.resolve()
        : new Promise<void>((resolve) => {
            waiters.set(id, [...(waiters.get(id) ?? []), resolve])
          }),
  }
}

function createQueue() {
  const all: HeldRequest[] = []
  const unclaimed: HeldRequest[] = []
  const waiters: Array<{
    predicate: (item: HeldRequest) => boolean
    resolve: (item: HeldRequest) => void
  }> = []

  return {
    all,
    push(item: HeldRequest) {
      all.push(item)
      const index = waiters.findIndex((waiter) => waiter.predicate(item))

      if (index >= 0) {
        const [waiter] = waiters.splice(index, 1)
        waiter.resolve(item)
        return
      }

      unclaimed.push(item)
    },
    /** 条件に合う未取得のリクエストを到着順に1件返す。まだ無ければ到着を待つ */
    next(predicate: (item: HeldRequest) => boolean) {
      const index = unclaimed.findIndex(predicate)

      if (index >= 0) {
        return Promise.resolve(unclaimed.splice(index, 1)[0])
      }

      return new Promise<HeldRequest>((resolve) => {
        waiters.push({ predicate, resolve })
      })
    },
  }
}

const test = base.extend<{ tagApi: TagApi }>({
  // 第2引数は慣例では use だが、ReactのHook(use)と誤認されないよう別名にする
  tagApi: async ({ page, baseURL }, provide) => {
    if (!baseURL) {
      throw new Error('playwright.config.ts の baseURL が未設定です')
    }

    // 許可するフロントエンドのoriginは、設定されたbaseURLから取得する
    const frontendOrigin = new URL(baseURL).origin
    const CORS_HEADERS = corsHeaders(frontendOrigin)
    const unexpected: string[] = []
    const queue = createQueue()
    let sequence = 0

    // フロントエンド以外への通信は遮断して記録する
    // (後から登録したrouteが優先されるため、バックエンド用はこの後に登録する)
    await page.route('**/*', async (route) => {
      const url = new URL(route.request().url())

      if (url.origin === frontendOrigin) {
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

        if (method === 'OPTIONS' && url.pathname === '/api/tags') {
          await route.fulfill({ status: 204, headers: CORS_HEADERS })
          return
        }

        if (method === 'GET' && url.pathname === '/api/categories') {
          await route.fulfill({
            status: 200,
            headers: CORS_HEADERS,
            contentType: 'application/json',
            body: JSON.stringify({ categories: CATEGORIES }),
          })
          return
        }

        if (
          url.pathname === '/api/tags' &&
          (method === 'GET' || method === 'POST')
        ) {
          sequence += 1
          const id = `tags-${sequence}`
          let resolveResponse!: (response: MockResponse) => void
          const responsePromise = new Promise<MockResponse>((resolve) => {
            resolveResponse = resolve
          })

          const item: HeldRequest = {
            id,
            method,
            keyword: method === 'GET' ? url.searchParams.get('search') : null,
            name: method === 'POST' ? (request.postDataJSON()?.name ?? null) : null,
            released: false,
            respond: (response) => {
              item.released = true
              resolveResponse(response)
            },
            release: async (response) => {
              item.respond(response)
              await page.evaluate(
                (mockId) => window.__tagsE2E.whenBodyRead(mockId),
                id,
              )
            },
          }

          queue.push(item)
          const response = await responsePromise
          await route.fulfill({
            status: response.status,
            headers: { ...CORS_HEADERS, [MOCK_ID_HEADER]: id },
            contentType: 'application/json',
            body: JSON.stringify(response.body),
          })
          return
        }

        unexpected.push(`${method} ${url.href}`)
        await route.abort()
      },
    )

    await page.addInitScript(E2E_INIT_SCRIPT, {
      backendOrigin: BACKEND_ORIGIN,
      mockIdHeader: MOCK_ID_HEADER,
    })

    await provide({
      nextSearch: (keyword) =>
        queue.next(
          (item) =>
            item.method === 'GET' &&
            (keyword === undefined || item.keyword === keyword),
        ),
      nextCreate: () => queue.next((item) => item.method === 'POST'),
      searchRequests: () => queue.all.filter((item) => item.method === 'GET'),
      createRequests: () => queue.all.filter((item) => item.method === 'POST'),
    })

    // 保留したまま終わったリクエストを解放してから、想定外の通信が無いことを確認する
    for (const item of queue.all) {
      if (!item.released) {
        item.respond(tagsResponse([]))
      }
    }

    expect(unexpected, '想定外の通信がありました').toEqual([])
  },
})

const tagInput = (page: Page) => page.getByPlaceholder('例: エネルギー政策')
const suggestion = (page: Page, name: string) =>
  page.getByRole('button', { name: `#${name}`, exact: true })
const addButton = (page: Page) =>
  page.getByRole('button', { name: 'タグを追加', exact: true })
const removeButton = (page: Page, name: string) =>
  page.getByRole('button', { name: `${name} を削除`, exact: true })
const searchingText = (page: Page) => page.getByText('検索中...')
const creatingText = (page: Page) => page.getByText('タグを作成中...')
const searchError = (page: Page) => page.getByText('タグの取得に失敗しました。')

/**
 * check が OBSERVATION_MS のあいだ成り立ち続けることを確認する。
 * check の中では再試行付きアサーションを使わず、その時点の値を1回だけ読んで判定する
 * (再試行すると、観測中の一時的な違反を見逃すため)。
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

async function tagFetchCalls(page: Page) {
  return page.evaluate(() => window.__tagsE2E.calls())
}

async function openCreatePage(page: Page) {
  await page.goto('/posts/create')
  await expect(tagInput(page)).toBeVisible()
}

test('応答順が逆転しても、最新の検索結果が古い結果で上書きされない', async ({
  page,
  tagApi,
}) => {
  await openCreatePage(page)

  await tagInput(page).fill('ab')
  const older = await tagApi.nextSearch('ab')

  await tagInput(page).fill('abc')
  const latest = await tagApi.nextSearch('abc')

  await latest.release(tagsResponse([tag(2, 'abc-latest')]))
  await expect(suggestion(page, 'abc-latest')).toBeVisible()

  await older.release(tagsResponse([tag(1, 'ab-old')]))
  await expectStable(page, async () => {
    expect(await suggestion(page, 'ab-old').count()).toBe(0)
    expect(await suggestion(page, 'abc-latest').count()).toBe(1)
  })
})

test('古い検索が先に完了しても、新しい検索の検索中表示が消えない', async ({
  page,
  tagApi,
}) => {
  await openCreatePage(page)

  await tagInput(page).fill('ab')
  const older = await tagApi.nextSearch('ab')

  await tagInput(page).fill('abc')
  const latest = await tagApi.nextSearch('abc')
  await expect(searchingText(page)).toBeVisible()

  await older.release(tagsResponse([tag(1, 'ab-old')]))
  await expectStable(page, async () => {
    expect(await searchingText(page).count()).toBe(1)
    expect(await suggestion(page, 'ab-old').count()).toBe(0)
    expect(await addButton(page).isDisabled()).toBe(true)
  })

  await latest.release(tagsResponse([tag(2, 'abc-latest')]))
  await expect(searchingText(page)).toHaveCount(0)
  await expect(suggestion(page, 'abc-latest')).toBeVisible()
})

test('古い検索の失敗は、最新の検索結果にエラーを表示しない', async ({
  page,
  tagApi,
}) => {
  await openCreatePage(page)

  await tagInput(page).fill('ab')
  const older = await tagApi.nextSearch('ab')

  await tagInput(page).fill('abc')
  const latest = await tagApi.nextSearch('abc')

  await latest.release(tagsResponse([tag(2, 'abc-latest')]))
  await expect(suggestion(page, 'abc-latest')).toBeVisible()

  await older.release(serverError)
  await expectStable(page, async () => {
    expect(await searchError(page).count()).toBe(0)
    expect(await suggestion(page, 'abc-latest').count()).toBe(1)
  })

  // 最新の検索の失敗は、これまでどおり表示される
  await tagInput(page).fill('abcd')
  const failing = await tagApi.nextSearch('abcd')
  await failing.release(serverError)
  await expect(searchError(page)).toBeVisible()
})

test('入力を全消去した後に届いた古い結果・エラーは反映されない', async ({
  page,
  tagApi,
}) => {
  await openCreatePage(page)

  // 失敗する古い検索
  await tagInput(page).fill('ab')
  const failing = await tagApi.nextSearch('ab')
  await tagInput(page).fill('')
  await failing.release(serverError)
  await expectStable(page, async () => {
    expect(await searchError(page).count()).toBe(0)
  })

  // 成功する古い検索
  await tagInput(page).fill('cd')
  const stale = await tagApi.nextSearch('cd')
  await tagInput(page).fill('')
  await stale.release(tagsResponse([tag(1, 'cd-stale')]))

  // 再入力後、次の検索の結果が届くまでのあいだも古い候補は出ない
  await tagInput(page).fill('cd')
  await expectStable(page, async () => {
    expect(await suggestion(page, 'cd-stale').count()).toBe(0)
  })

  const latest = await tagApi.nextSearch('cd')
  await latest.release(tagsResponse([]))
  await expect(
    page.getByText('Enter で「cd」を新規タグとして追加'),
  ).toBeVisible()
  await expectStable(page, async () => {
    expect(await suggestion(page, 'cd-stale').count()).toBe(0)
    expect(await searchError(page).count()).toBe(0)
  })
})

test('タグ追加後に届いた古い結果で、追加済みタグが候補に戻らない', async ({
  page,
  tagApi,
}) => {
  await openCreatePage(page)

  await tagInput(page).fill('tag')
  const first = await tagApi.nextSearch('tag')
  await first.release(tagsResponse([tag(1, 'tagA'), tag(2, 'tagB')]))
  await expect(suggestion(page, 'tagA')).toBeVisible()

  // 「tagA」の検索が保留中のまま、候補にある「tagA」をEnterで追加する
  await tagInput(page).fill('tagA')
  const inFlight = await tagApi.nextSearch('tagA')
  await tagInput(page).press('Enter')
  await expect(removeButton(page, 'tagA')).toBeVisible()
  await expect(tagInput(page)).toHaveValue('')

  // 追加前のタグで絞り込まれた古い結果(tagAを含む)を解放し、観測時間のあいだ
  // 追加済みの状態が維持されることを確認する
  await inFlight.release(tagsResponse([tag(1, 'tagA')]))
  await expectStable(page, async () => {
    expect(await tagInput(page).inputValue()).toBe('')
    expect(await removeButton(page, 'tagA').count()).toBe(1)
  })

  // 古い結果が候補に入っていれば、候補の「tagA」に一致して入力が残る。
  // 入っていなければ、選択済みの名前として入力が消える。
  await tagInput(page).fill('tagA')
  await tagInput(page).press('Enter')
  await expect(tagInput(page)).toHaveValue('')
  await expectStable(page, async () => {
    expect(
      (await tagFetchCalls(page)).filter((call) => call.method === 'POST'),
    ).toHaveLength(0)
  })

  // 最新の検索では、追加済みのタグが候補から除外される
  await tagInput(page).fill('tag')
  const latest = await tagApi.nextSearch('tag')
  await latest.release(tagsResponse([tag(1, 'tagA'), tag(2, 'tagB')]))
  await expect(suggestion(page, 'tagB')).toBeVisible()
  await expectStable(page, async () => {
    expect(await suggestion(page, 'tagA').count()).toBe(0)
  })
})

test('入力を残してタグを削除すると、最新の候補が反映される', async ({
  page,
  tagApi,
}) => {
  await openCreatePage(page)

  await tagInput(page).fill('tag')
  const first = await tagApi.nextSearch('tag')
  await first.release(tagsResponse([tag(1, 'tagA'), tag(2, 'tagB')]))
  await suggestion(page, 'tagA').click()
  await expect(removeButton(page, 'tagA')).toBeVisible()

  // 入力を残した状態で検索を保留し、その間にタグを削除する
  await tagInput(page).fill('ta')
  const beforeRemove = await tagApi.nextSearch('ta')
  await removeButton(page, 'tagA').click()
  const afterRemove = await tagApi.nextSearch('ta')

  await afterRemove.release(tagsResponse([tag(1, 'tagA'), tag(2, 'tagB')]))
  await expect(suggestion(page, 'tagA')).toBeVisible()
  await expect(suggestion(page, 'tagB')).toBeVisible()

  // 削除前のタグで絞り込まれた古い結果(tagAを除外)が後から届いても反映されない
  await beforeRemove.release(tagsResponse([tag(2, 'tagB')]))
  await expectStable(page, async () => {
    expect(await suggestion(page, 'tagA').count()).toBe(1)
    expect(await suggestion(page, 'tagB').count()).toBe(1)
  })
})

test('タグ作成中は、検索が完了しても作成中状態が解除されず、重複作成されない', async ({
  page,
  tagApi,
}) => {
  await openCreatePage(page)

  await tagInput(page).fill('new')
  const search = await tagApi.nextSearch('new')
  await search.release(tagsResponse([]))
  await expect(
    page.getByText('Enter で「new」を新規タグとして追加'),
  ).toBeVisible()

  await tagInput(page).press('Enter')
  const creating = await tagApi.nextCreate()
  expect(creating.name).toBe('new')
  await expect(creatingText(page)).toBeVisible()
  await expect(addButton(page)).toBeDisabled()

  // 作成中に入力を変えて検索を完了させても、作成中状態は解除されない
  await tagInput(page).fill('new2')
  const searchWhileCreating = await tagApi.nextSearch('new2')
  await searchWhileCreating.release(tagsResponse([]))
  await expectStable(page, async () => {
    expect(await creatingText(page).count()).toBe(1)
    expect(await addButton(page).isDisabled()).toBe(true)
  })

  // 作成中のEnterでは、2回目の作成リクエストを送らない
  await tagInput(page).press('Enter')
  await expectStable(page, async () => {
    expect(
      (await tagFetchCalls(page)).filter((call) => call.method === 'POST'),
    ).toHaveLength(1)
  })

  await creating.release(createdResponse(tag(30, 'new')))
  await expect(removeButton(page, 'new')).toBeVisible()
  await expect(creatingText(page)).toHaveCount(0)
  expect(tagApi.createRequests()).toHaveLength(1)
})

test('タグ作成の失敗は、後続の検索で消えず、検索中表示も解除しない', async ({
  page,
  tagApi,
}) => {
  const createError = 'タグ名を入力してください。'

  await openCreatePage(page)

  await tagInput(page).fill('bad')
  const search = await tagApi.nextSearch('bad')
  await search.release(tagsResponse([]))

  await tagInput(page).press('Enter')
  const creating = await tagApi.nextCreate()

  // 作成の応答待ちの間に、別の検索を開始させる
  await tagInput(page).fill('bad2')
  const searchWhileCreating = await tagApi.nextSearch('bad2')

  await creating.release({ status: 422, body: { message: createError } })
  await expect(page.getByText(createError)).toBeVisible()
  // 作成の完了で、進行中の検索の検索中表示は解除されない
  await expect(searchingText(page)).toBeVisible()
  await expectStable(page, async () => {
    expect(await searchingText(page).count()).toBe(1)
    expect(await page.getByText(createError).count()).toBe(1)
  })

  await searchWhileCreating.release(tagsResponse([]))
  await expect(searchingText(page)).toHaveCount(0)
  await expectStable(page, async () => {
    expect(await page.getByText(createError).count()).toBe(1)
  })

  // 作成失敗の後に始まった検索でも、作成エラーは消えない
  await tagInput(page).fill('bad3')
  const laterSearch = await tagApi.nextSearch('bad3')
  await expect(searchingText(page)).toBeVisible()
  await expect(page.getByText(createError)).toBeVisible()
  await laterSearch.release(tagsResponse([]))
  await expect(searchingText(page)).toHaveCount(0)
  await expectStable(page, async () => {
    expect(await page.getByText(createError).count()).toBe(1)
  })
})

test('連続した入力は、最後の入力から250ms後に最新キーワードで1回だけ検索する', async ({
  page,
  tagApi,
}) => {
  // ページ読み込み中は時計を通常どおり進め、読み込み後に停止する
  await page.clock.install()
  await openCreatePage(page)
  const now = await page.evaluate(() => Date.now())
  await page.clock.pauseAt(now + 1_000)

  const input = tagInput(page)
  await input.press('a')
  await page.clock.runFor(100)
  await input.press('b')
  await page.clock.runFor(100)
  await input.press('c')
  await expect(input).toHaveValue('abc')

  // 「a」から200ms経過しても、最後の入力から250ms未満なので検索しない
  await page.clock.runFor(249)
  expect(await tagFetchCalls(page)).toEqual([])

  await page.clock.runFor(1)
  expect(await tagFetchCalls(page)).toEqual([
    { method: 'GET', keyword: 'abc' },
  ])

  const request = await tagApi.nextSearch()
  expect(request.keyword).toBe('abc')
  await request.release(tagsResponse([tag(1, 'abc')]))
  await expect(suggestion(page, 'abc')).toBeVisible()

  // その後も時間を進めて、追加の検索が発生しないことを確認する
  await page.clock.runFor(1_000)
  expect(await tagFetchCalls(page)).toHaveLength(1)
  expect(tagApi.searchRequests()).toHaveLength(1)
})
