## 信頼スコア計算ロジック（簡易版）
スコア構造（シンプル版）
信頼スコア = 
  プロフィールスコア
+ 投稿スコア
+ ソーススコア
+ 履歴スコア

① プロフィールスコア
公開している情報の量で決まる
姓のみ           +10
氏名公開         +20
年齢             +10
学歴             +20
職歴             +20

② 投稿スコア
投稿の質
文字数（長文）     +10
構造（見出しあり） +10
カテゴリ設定       +5

③ ソーススコア（重要）
根拠の有無
参考URLあり        +20
書籍・論文あり     +30
複数ソース         +10

④ 履歴スコア
過去との一貫性
同じ主張を継続     +20
矛盾が少ない       +20
投稿数（蓄積）     +10

合計イメージ
プロフィール     60
投稿             25
ソース           30
履歴             40
-------------------
合計             155

正規化（表示用）
最大値：200
↓
155 / 200 = 77%

UI表示
信頼度：77%

★★★★☆

## 添付ファイル(PostAttachment)の保存・削除の失敗時挙動

`PostAttachmentService`（`backend/app/Domain/Post/Services/PostAttachmentService.php`）が対応した内容と、残る制約のメモ。

対応した内容：
- 保存：`store()`がfalse/例外を返した場合は該当ファイルのDBレコードを作らない。DB登録は`DB::transaction()`でまとめ、途中失敗時は今回分のDB登録をロールバックし、今回保存済みのファイルを後片付け（削除）する。
- 削除：`delete()`がfalse/例外の場合はDBレコードを消さない。ファイル削除に成功した後だけDBレコードを削除する。
- 後片付け・削除失敗は`PostAttachmentOperationFailedException`として`report()`で記録し、利用者へは内部詳細を含まない汎用メッセージ＋500を返す（`PostAttachmentController`）。
- ログのcontextには操作名`operation`を必ず付与する（`store_file`／`register_attachment`／`delete_file`／`delete_record`／`cleanup_file`）。あわせて`post_id`、削除系は`attachment_id`、保存・後片付け系は`file_name`／`file_path`のみ（トークン・ファイル内容は含めない）。
- 入力の`files`が飛び番号（例: `files[2]`のみ）や`[1, 0]`の順でも、`store()`の入口で`array_values()`により走査順を保ったまま連番化し、ファイルと保存パスの対応ずれ（500・パス入れ替わり）を防ぐ。

残る制約（今回の対応では保証できない）：
- ファイル操作はDBトランザクションの対象外。DBと完全に原子的ではない。
- プロセスの強制終了、DBのコミット結果が不明になるような接続障害には対応できない。
- 「ファイル削除成功後にDBレコード削除が失敗」した場合、ファイルは復元できない。DBレコードは残るため、同じ添付に対して削除を再実行すれば完了できる（file-goneのケースは削除がno-op成功になることを確認済み。ローカル/公開ディスクの挙動として確認、S3等の他ディスクドライバは未確認）。
- 同時操作対策・ロック方式の変更、キュー化、退避ファイル、論理削除は導入していない。

## 認証(登録・ログイン・ログアウト)の責務分離と登録のトランザクション化

`AuthController`（`backend/app/Http/Controllers/AuthController.php`）が登録・ログイン・ログアウト・`/me`のHTTP処理を、`AuthService`（`backend/app/Domain/Auth/Services/AuthService.php`）が登録時のDB操作を担当する。

意図した挙動変更：
- 登録（User作成→Observer経由のTrustScore→Profile→公開設定3件）を1つの`DB::transaction()`で囲んだ。途中で失敗した場合、今回分のデータはすべてロールバックされ、部分的なUserなどは残らない。

維持した仕様：
- URL・ミドルウェア・ステータスコード・JSON・検証順序・メッセージ。登録レスポンスの`user`にはObserver内で読み込まれた`profile`(null)・`educations`・`careers`が含まれる。このため作成順序を変えたり、`fresh()`や追加のリレーション読み込みをしたりするとレスポンスが変わる。
- ログインの処理順序（認証→閲覧履歴クリア→ユーザー取得→トークン発行）。

残る課題（今回は対応していない。別途検討）：
- 登録・ログインのレート制限がない。
- トークンは無期限・全権限で、ログインごとに増え、旧トークンは整理されない。フロントエンドは`localStorage`に保存している。
- ログアウトは現在のBearerトークン1件のみ削除する。セッションの無効化・CSRFトークン再生成は行わない。
- Cookie(ステートフル)認証で`/logout`に到達した場合、`TransientToken`に`delete()`がないためエラーになり得る。現状はステートフル設定がなく到達しない見込みだが、実通信では未確認。
- 登録の`unique:users,email`により、登録済みメールアドレスが判別できる。
- ログインは認証後に`User::where('email')`で再取得している（`Auth::user()`で足りる可能性）。
- `PostController::clearViewedPostsFromSession()`は認証側から呼ばなくなったため、他に呼び出し元がなければ削除できる。

## テスト用DB安全ガード(TestDatabaseGuard)

Dockerなどから引き継いだ環境変数によって、テストの`RefreshDatabase`(`migrate:fresh`)が開発用PostgreSQL等を初期化してしまう事故を防ぐための仕組み。

対応した内容：
- `backend/phpunit.xml`の`APP_ENV`・`DB_CONNECTION`・`DB_DATABASE`・`DB_URL`に`force="true"`を追加し、Docker等が既に設定した環境変数より優先させる。ただしこれは環境変数の解決順序を固定するだけで、設定キャッシュ(`bootstrap/cache/config.php`)が存在する場合は`env()`自体が呼ばれないため効かない。
- `backend/tests/Support/TestDatabaseGuard.php`(新規)が、実効的な接続設定(`Illuminate\Support\ConfigurationUrlParser`で`DB_URL`等による上書きも解決した後の値)を検査する。許可するのは「driverが厳密に`sqlite`」かつ「databaseが厳密に`:memory:`」の場合のみ。`APP_ENV`・接続名・DB名の末尾は判定に使わない。
- 検査対象は`database.default`(必須)と、テストクラスが独自定義した`$connectionsToTransact`(`RefreshDatabase`が参照するのと同じプロパティ)の和集合。片方だけが安全でも拒否する。接続名がnullならデフォルト接続として扱う(Laravel本体の`DatabaseManager::connection()`と同じ解決規則)。空文字・文字列以外の型・未定義の接続名は安全側に拒否する。
- `backend/tests/TestCase.php`の`createApplication()`で、`Illuminate\Foundation\Bootstrap\LoadConfiguration`のbootstrap完了直後(`RegisterProviders`/`BootProviders`より前)にガードを実行するよう`$app->afterBootstrapping(LoadConfiguration::class, ...)`を登録している。設定確定後・Provider登録前という位置は、Laravel本体のbootstrapper順序(`vendor/laravel/framework/.../Foundation/Console/Kernel.php`)から導いたもの。`createApplication()`自体は`Illuminate\Foundation\Testing\TestCase::createApplication()`(laravel/framework ^13.7時点)をそのまま複製し、ガード登録を1行足しただけで、既存の動作(`WithCachedConfig`/`WithCachedRoutes`の反映など)は変更していない。

**実行して確認したこと(今回時点):**
- `backend/tests/Support/TestDatabaseGuard.php`の判定ロジック(`rejectionReason()`・`assertSafe()`)を対象にした、Laravelを一切起動しない独立した単体テスト(`backend/tests/Unit/Support/TestDatabaseGuardTest.php`・`TestDatabaseGuardAssertSafeTest.php`、計24件)のみ。実行コマンドは次のとおりで、`vendor/autoload.php`だけを読み込み、Laravelのbootstrap・拡張・他テストは一切読み込まない。
  ```bash
  cd backend
  vendor/bin/phpunit --no-configuration --bootstrap=vendor/autoload.php tests/Unit/Support/
  ```
- `assertSafe()`のテストは、`Illuminate\Contracts\Foundation\Application`をMockeryでモックし、`make('config')`以外の呼び出し(DB接続の解決など)が起きたら即座に失敗する構成にしている。設定側は実際の`Illuminate\Config\Repository`をそのまま使う。

**まだ実行確認していないこと(区別して記録する):**
- 既存のFeatureテスト・バックエンドテスト全体・E2Eは、ガード導入後に一度も実行していない。
- 通常のテストスイート(`tests/Feature`・`tests/Unit`全体)を`vendor/bin/phpunit`で直接実行し、ガードがLaravel起動の一部として実際に機能することは、今後の検証候補であり、まだ実行確認できていない。
- ガードが「Providerの登録・bootより前に呼ばれること」自体は、Laravel本体のソースコード(`Application::bootstrapWith()`の同期forEachループとイベント発火順)から構造的に導けるが、実行時に確認したものではない。

**安全な入口についての訂正:**
- `composer test`・`php artisan test`は、PHPUnitの前にArtisanコマンドとして起動する。この先行するArtisan起動処理は、今回のガードの保証範囲外であり、保護できるとは主張しない。
- ガードが実際に効くのは、PHPUnitのプロセスが個々のテストの`setUp()`に到達し、`Tests\TestCase::createApplication()`が呼ばれてから`LoadConfiguration`のbootstrapが完了した時点以降。
- 今回確認できた安全な実行経路は、上記の独立したガード単体テストのみ。通常のFeatureテストを含むスイート全体を`vendor/bin/phpunit`/`composer test`/`php artisan test`のいずれで実行した場合でも、ガードが実際に安全側で機能することは、まだ実行して確認していない。
- 登録・ログインのテストはSQLite(`:memory:`)のみで、PostgreSQL・ブラウザ・実際のCookie通信は未検証。

**追記(2026-09-22):** 上記の「まだ実行確認していないこと」「安全な入口についての訂正」は、その時点までの状況である。この後、開発用DBに一切接続できない隔離コンテナ環境で、通常スイート(`tests/Unit`+`tests/Feature`)と`tests/Integration`を実際に実行し、いずれも成功したことを確認した。隔離条件・実行手順・結果の詳細は重複を避けるため記載しない。[`docs/backend-isolated-verification.md`](backend-isolated-verification.md) を参照。PostgreSQL・ブラウザでの確認は今回も未実施のまま。

## 投稿削除の仕様(公開済み投稿は削除不可)

確定した仕様：
- 公開済み投稿は、投稿者本人でも削除できない。
- 下書き投稿は、投稿者本人のみ削除できる(`status`を`deleted`にする論理削除)。
- 公開済み投稿の添付ファイルは、投稿者本人でも削除できない。
- 下書き投稿の添付ファイルは、投稿者本人のみ削除できる。
- 既存の削除済み(`deleted`)データは復元せず、引き続き一覧・詳細から非表示とする。

実装：
- `PostPolicy::delete`を「本人かつ`draft`」に限定した。公開済み・削除済みへのDELETEは403。
- 添付の削除は`PostPolicy::detachAttachment`(本人かつ`draft`)に分離した。添付の追加(`attach`)は「本人かつ`deleted`以外」のまま。作成画面が「投稿を公開してから添付をアップロードする」順序のため、追加を`draft`限定にすると公開時の添付が失敗する。
- 投稿詳細画面の削除ボタンは「本人かつ`draft`」の場合のみ表示する。

今後の課題(今回は実装していない)：
- 管理者による公開済み投稿の非公開化(管理者の概念自体が未実装)。
- 個人情報の誤掲載・権利侵害・通報などへの救済手段。現状、公開済み投稿は投稿者本人も運営も取り下げる手段がない。
- 削除の判定はPolicy(HTTP経由)で行っており、`PostService::destroy`・`PostAttachmentService::destroy`自体は投稿の状態を確認しない。
