// E2Eテストで使う認証情報を環境変数から読み込むための小さなヘルパー。
//
// - 実在の認証情報や固定パスワードへのフォールバックは行わない。
// - 未設定・空文字の場合は、値を表示せず必要な変数名だけを示して例外を投げる
//   (test.skip() 等で黙って成功扱いにしない)。
// - 認証情報を必要としないテスト(ページ表示のみの確認など)からは呼び出さない
//   ことで、E2E用変数を通常の開発・ビルドや他のテストに必須化しない。

export type E2EUser = {
  email: string;
  password: string;
};

/**
 * 指定した環境変数群が、未設定・空文字なく揃っていることを確認して返す。
 * 1つでも欠けていれば、値を出力せず変数名だけを含めて例外を投げる。
 */
function requireEnv(varNames: readonly string[]): Record<string, string> {
  const missing = varNames.filter((name) => !process.env[name]);

  if (missing.length > 0) {
    throw new Error(
      `E2Eテストに必要な環境変数が未設定です: ${missing.join(', ')}\n` +
        'frontend/.env.e2e.local (Git管理対象外) を作成し、' +
        'frontend/.env.e2e.example を参考に値を設定してください。',
    );
  }

  return Object.fromEntries(
    varNames.map((name) => [name, process.env[name] as string]),
  );
}

/**
 * ログインに使うE2E専用アカウント(実アカウントではなく、隔離されたテスト環境の
 * 専用アカウントを使う想定)の認証情報を返す。
 * E2E_USER_EMAIL / E2E_USER_PASSWORD が未設定・空文字の場合は例外を投げる。
 */
export function requireE2EUser(): E2EUser {
  const env = requireEnv(['E2E_USER_EMAIL', 'E2E_USER_PASSWORD']);

  return {
    email: env.E2E_USER_EMAIL,
    password: env.E2E_USER_PASSWORD,
  };
}
