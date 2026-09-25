import { defineConfig } from '@playwright/test'
import path from 'node:path'

// E2E専用の認証情報 (E2E_USER_EMAIL / E2E_USER_PASSWORD 等) を、Git管理対象外の
// .env.e2e.local から読み込む。設定例は .env.e2e.example を参照。
// このファイルは next dev / next build からは参照されないため、E2E用変数が
// 通常の開発・ビルドに必須化されることはない。
//
// package.json に "type": "module" が無いため、Playwrightはこの設定ファイルを
// CommonJSとして require() する(node_modules/playwright/lib/common/index.js の
// requireOrImport() が fileIsModule() で package.json の "type" を見て判定し、
// falseならNode標準のrequire()経路に乗る)。CommonJSとしてrequire()される限り、
// Node本体がモジュールラッパーで __dirname を提供するため、リポジトリルートなど
// カレントディレクトリが異なる場所から起動しても、このファイル(frontend/)を
// 基準に .env.e2e.local を解決できる。
// (package.json に "type": "module" を追加するとこの前提が崩れる点に注意)
//
// ファイルが存在しない場合は何もせず読み飛ばす。実際に認証情報を必要とする
// テストの実行時に、e2e/env.ts が変数名を示して明確に失敗させる。
try {
  process.loadEnvFile(path.join(__dirname, '.env.e2e.local'))
} catch (error) {
  if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
    throw error
  }
}

// E2E_PORT を指定すると、そのポートで起動した開発サーバーだけを使う
// (既存サーバーは再利用しない)。未指定時は従来どおり3000番で、起動済みの
// サーバーがあれば再利用する。
const e2ePort = process.env.E2E_PORT

if (e2ePort !== undefined && !/^[1-9]\d{0,4}$/.test(e2ePort)) {
  throw new Error(`E2E_PORT が不正です: ${e2ePort}`)
}

const baseURL = `http://localhost:${e2ePort ?? 3000}`

export default defineConfig({
  testDir: './e2e',
  use: {
    baseURL,
  },
  webServer: {
    command: e2ePort ? `npm run dev -- --port ${e2ePort}` : 'npm run dev',
    url: baseURL,
    reuseExistingServer: e2ePort === undefined,
  },
})