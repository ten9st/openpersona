<?php

namespace Tests\Integration;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Tests\Support\DatabaseResolutionAttemptedException;
use Tests\Support\DatabaseResolutionSentinel;
use Tests\Support\RecordingServiceProvider;
use Tests\Support\TestDatabaseGuard;
use Throwable;

/**
 * TestDatabaseGuardが、実際のLaravel起動経路(Illuminate\Foundation\Application::
 * bootstrapWith())の中で、Provider登録・bootより前に本当に働くことを検証する結合テスト。
 *
 * - backend/bootstrap/app.php(本物のアプリ)は一切requireしない。各テストが
 *   sys_get_temp_dir() 配下に、その場限りの最小Laravelアプリの骨組み
 *   (bootstrap/app.php・bootstrap/providers.php・config/app.php・config/database.php)
 *   を作り、tearDown()で丸ごと削除する。
 * - 判定ロジック(TestDatabaseGuard::rejectionReason/assertSafe)もフック登録
 *   (TestDatabaseGuard::registerHook)も、実際のクラスをそのまま使う。テスト内に
 *   複製しない。tests/TestCase.php も同じ registerHook() を呼ぶ。
 * - config/app.php の 'providers' は検証用Provider(RecordingServiceProvider)のみを
 *   明示指定し、Laravel標準のデフォルトProvider群(Illuminate\Support\DefaultProviders、
 *   DatabaseServiceProviderを含む23件)は含めない。これにより、「安全な設定で正常に
 *   bootstrapが完了するケース」でも、Illuminate\Database\DatabaseServiceProvider::boot()
 *   が行う Model::setConnectionResolver($this->app['db']) のような、Laravel標準の
 *   正当な'db'解決さえも一切発生しない、必要最小限の構成にしている。
 *   パッケージ自動検出(Illuminate\Foundation\PackageManifest)は、一時ディレクトリ配下に
 *   vendor/を作っていないため、実際には何も検出しない
 *   (PackageManifest::build()はvendor/composer/installed.jsonが存在しない場合、
 *   空のマニフェストを書き込む。無効化のための追加設定は不要だった)。
 * - DatabaseResolutionSentinel(tests/Support/DatabaseResolutionSentinel.php)を、
 *   bootstrap開始前に全ケースへ設置する。Illuminate\Container\Container::
 *   beforeResolving()のグローバルコールバックとして登録され、DB関連の抽象が
 *   コンテナから解決されようとした瞬間(実際のbinding解決より前)に例外を投げる。
 *   「到達不能なダミーIPアドレスだから安全」という主張はしない
 *   (実際にそのIPへの接続を試みるコードが将来紛れ込んだ場合、到達不能性は
 *   ネットワーク到達の可否を左右するだけで、コンテナからのDBサービス解決や
 *   PDOインスタンス化そのものを防ぐものではない)。ダミーのPostgreSQL設定・IPは
 *   今回も維持するが、あくまで「実在する開発用DBの値を使わない」ための配慮であり、
 *   安全性の根拠はDatabaseResolutionSentinelとProvider最小化の方に置いている。
 * - #[RunTestsInSeparateProcesses] により、このクラスの各テストメソッドはPHPUnitに
 *   よって完全に別のPHPプロセスで実行される。RecordingServiceProvider::$log・
 *   DatabaseResolutionSentinel::$attemptedAbstracts、およびLaravel側のグローバル
 *   静的状態(RegisterProviders・HandleExceptions・Facadeの紐付け等)が、あるテスト
 *   から別のテストへ、あるいはこのファイルの外側の呼び出し元プロセスへ漏れる
 *   ことはない。tearDown()での復元処理はこの分離があっても引き続き行い、各プロセス
 *   自体を終了直前まで正しい状態に保つ。
 * - Laravel本体は設定キャッシュ・サービスキャッシュ・パッケージキャッシュ・
 *   ルートキャッシュ・イベントキャッシュの実効パスをそれぞれ APP_CONFIG_CACHE・
 *   APP_SERVICES_CACHE・APP_PACKAGES_CACHE・APP_ROUTES_CACHE・APP_EVENTS_CACHE
 *   (Illuminate\Foundation\Application::normalizeCachePath())、パッケージ自動検出の
 *   vendorディレクトリを COMPOSER_VENDOR_DIR
 *   (Illuminate\Foundation\PackageManifest::__construct())から、いずれも
 *   Illuminate\Support\Env::get() 経由で読む。このEnv::get()の実体は
 *   vlucas/phpdotenvのRepositoryで、既定のリーダー順は
 *   ServerConstAdapter($_SERVER)→EnvConstAdapter($_ENV)→PutenvAdapter(getenv())の
 *   「最初に値がある方が勝つ」順であることをソースで確認している
 *   (vendor/vlucas/phpdotenv/src/Repository/Adapter/MultiReader::read())。
 *   そのためputenv()だけを書き換えても、外部から既に$_SERVER/$_ENVに値が
 *   入っていればそちらが優先されてしまう。pinFakeAppPaths()はこの6変数すべてを
 *   putenv()・$_ENV・$_SERVERの3箇所同時に、最小アプリのアプリ生成(require
 *   bootstrap/app.php)・bootstrap()より前に一時ディレクトリ内のパスへ固定し、
 *   tearDown()で元の状態(putenv/$_ENV/$_SERVERそれぞれの有無・値)へ復元する。
 *   vendorの参照先は、一時ディレクトリ内に新設する空ディレクトリ(vendor-empty、
 *   composer/installed.jsonを持たない)に固定するため、実プロジェクトの
 *   vendor/composer/installed.jsonへは一切アクセスしない。
 *
 * 実行方法(Laravelのbootstrap・拡張・他テストを一切読み込まない独立実行):
 *   cd backend
 *   vendor/bin/phpunit --no-configuration --bootstrap=vendor/autoload.php \
 *     tests/Integration/DatabaseGuardBootstrapIntegrationTest.php
 */
#[RunTestsInSeparateProcesses]
class DatabaseGuardBootstrapIntegrationTest extends TestCase
{
    /** setEnv()のバックアップで「元々未設定だった」ことを表す番兵値 */
    private const UNSET = "\0__unset__\0";

    /** @var array<int, string> このテストが作った一時ディレクトリ(tearDownで全削除) */
    private array $tempDirs = [];

    /**
     * setEnv()で一時的に変更した環境変数の元の値。putenv()・$_ENV・$_SERVERの
     * それぞれについて、元々未設定だったか('putenv'はfalse、'env'/'server'は
     * self::UNSET)、値があったか(その値そのもの)を保持する。
     *
     * @var array<string, array{putenv: string|false, env: string, server: string}>
     */
    private array $envBackup = [];

    protected function tearDown(): void
    {
        foreach (array_keys($this->envBackup) as $name) {
            $this->restoreEnv($name);
        }
        $this->envBackup = [];

        // Illuminate\Foundation\Bootstrap\HandleExceptions::bootstrap() は
        // set_error_handler()/set_exception_handler() でPHPのグローバルハンドラを
        // 書き換える。Laravel本体のテスト基盤(InteractsWithTestCaseLifecycle)が
        // 各テスト後に呼ぶのと同じ復元処理をそのまま使う。$thisを渡すのは、
        // PHPUnit\Runner\ErrorHandler::enable()がPHPUnit 12.3.4以降、
        // 非nullのTestCaseを要求するため(nullだとTypeErrorになる)。
        HandleExceptions::flushState($this);

        // Illuminate\Foundation\Bootstrap\RegisterProviders は、マージ対象のProvider
        // 一覧とbootstrap/providers.phpのパスをクラス静的プロパティに保持する。
        // 複数回 Application::configure()->withProviders() を呼ぶこのテストでは、
        // 次のテスト・次のアプリ起動に影響しないよう毎回リセットする。
        RegisterProviders::flushState();

        // Illuminate\Foundation\Bootstrap\RegisterFacades::bootstrap() が
        // Facade::setFacadeApplication() で直前に作った一時アプリをグローバルに
        // 紐付けるため、明示的に解除する。
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        RecordingServiceProvider::reset();
        DatabaseResolutionSentinel::reset();

        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectoryRecursively($dir);
            }
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // ケース1: 危険な設定で停止
    // ------------------------------------------------------------------
    public function test_dangerous_effective_config_is_rejected_before_providers_run(): void
    {
        $dir = $this->makeFakeApplication($this->dangerousPgsqlDatabaseConfig());

        $result = $this->bootFakeApplication($dir, registerGuard: true);

        $this->assertInstanceOf(\RuntimeException::class, $result['exception']);
        $this->assertStringContainsString('[pgsql]', $result['exception']->getMessage());
        // ガードによる「意図した拒否」であり、防壁(DatabaseResolutionSentinel)の
        // 例外ではないことを明示的に区別する。
        $this->assertNotInstanceOf(DatabaseResolutionAttemptedException::class, $result['exception']);

        $this->assertSame(
            [],
            RecordingServiceProvider::$log,
            '検証用Providerのregister()/boot()はどちらも呼ばれていないはずです。'
        );

        $this->assertSame(
            0,
            DatabaseResolutionSentinel::attempts(),
            'DB関連サービスの解決は一度も試みられていないはずです。'
        );
    }

    // ------------------------------------------------------------------
    // ケース2: 安全な設定で通過
    // ------------------------------------------------------------------
    public function test_safe_sqlite_memory_config_passes_and_providers_run(): void
    {
        $dir = $this->makeFakeApplication($this->safeSqliteDatabaseConfig());

        $result = $this->bootFakeApplication($dir, registerGuard: true);

        $this->assertNull(
            $result['exception'],
            $result['exception'] !== null ? 'ガードが誤って拒否しました: '.$result['exception']->getMessage() : ''
        );
        $this->assertSame(['register', 'boot'], RecordingServiceProvider::$log);

        $this->assertSame(
            0,
            DatabaseResolutionSentinel::attempts(),
            '安全な設定でのbootstrap完了までに、DB関連サービスの解決は一度も試みられていないはずです'
            .'(Provider構成を最小化しているため、Laravel標準のDatabaseServiceProviderによる'
            .'正当な\'db\'解決すら発生しない)。'
        );
    }

    // ------------------------------------------------------------------
    // ケース3: 設定キャッシュ由来の拒否
    // ------------------------------------------------------------------
    public function test_dangerous_config_cache_is_rejected_even_when_env_vars_say_sqlite(): void
    {
        // 環境変数はsqliteを示す(Docker等でこの変数だけ見れば安全に見えるケースの再現)。
        // ただしbootstrap/cache/config.phpが存在する場合、LoadEnvironmentVariables自体が
        // スキップされ(Application::configurationIsCached()がtrueになるため)、
        // LoadConfigurationもconfig/*.phpを一切読まずキャッシュ配列をそのまま使う。
        $this->setEnv('DB_CONNECTION', 'sqlite');
        $this->setEnv('DB_DATABASE', ':memory:');

        $cachedConfig = [
            'app' => [
                'name' => 'GuardIntegrationFakeApp',
                'env' => 'testing',
                'debug' => false,
                'timezone' => 'UTC',
                'key' => null,
                'providers' => [RecordingServiceProvider::class],
            ],
            'database' => $this->dangerousPgsqlDatabaseConfig(),
        ];

        $dir = $this->makeFakeApplication(
            $this->safeSqliteDatabaseConfig(), // config/database.php自体は安全(参照されないことの確認も兼ねる)
            cachedConfigArray: $cachedConfig,
        );

        $result = $this->bootFakeApplication($dir, registerGuard: true);

        $this->assertInstanceOf(\RuntimeException::class, $result['exception']);
        $this->assertStringContainsString('[pgsql]', $result['exception']->getMessage());
        $this->assertNotInstanceOf(DatabaseResolutionAttemptedException::class, $result['exception']);

        // 実際に「キャッシュから読み込まれた」ことを本体のフラグで確認する
        // (config/database.phpのファイルが読まれたのではないことの裏付け)。
        $this->assertTrue(
            $result['app']->bound('config_loaded_from_cache') && $result['app']->make('config_loaded_from_cache'),
            '設定キャッシュ経由で読み込まれたことを確認できませんでした。'
        );

        $this->assertSame([], RecordingServiceProvider::$log);
        $this->assertSame(0, DatabaseResolutionSentinel::attempts());
    }

    // ------------------------------------------------------------------
    // ケース4: 登録漏れの検出(ネガティブコントロール)
    // ------------------------------------------------------------------
    public function test_omitting_the_guard_registration_lets_the_dangerous_config_through(): void
    {
        // registerHook()を呼ばない状態を再現する。tests/TestCase.php や
        // TestDatabaseGuard.php 自体は一切変更しない(この呼び出しをスキップする
        // だけなので、元に戻す操作は不要)。これにより、ケース1で確認した拒否が
        // 「ガード登録」に起因していることを裏付ける。
        //
        // ガードが無い状態でも、DatabaseResolutionSentinelは設置されている
        // (makeFakeApplication/bootFakeApplicationが全ケース共通で設置するため)。
        // Provider構成を最小化しているため、DBサービスの解決自体がそもそも起きず、
        // このケースでもDB解決試行回数は0になる。これは「防壁が働いたから0」ではなく
        // 「そもそも誰も'db'を要求しない構成にしたから0」であり、両者を区別するため、
        // 別途 test_database_resolution_sentinel_blocks_explicit_db_resolution() で
        // 防壁自体が実際に機能することを確認している。
        $dir = $this->makeFakeApplication($this->dangerousPgsqlDatabaseConfig());

        $result = $this->bootFakeApplication($dir, registerGuard: false);

        $this->assertNull(
            $result['exception'],
            'ガード未登録なのに例外が発生しました: '.($result['exception']?->getMessage() ?? '')
        );

        $this->assertSame(
            'pgsql',
            $result['app']->make('config')->get('database.default'),
            '危険な設定がそのまま有効になっていることを確認できませんでした。'
        );

        $this->assertSame(
            ['register', 'boot'],
            RecordingServiceProvider::$log,
            'ガードが無いと、危険な設定のままProviderのregister/bootまで進んでしまうはずです。'
            .'(これはケース1でガードが実際に保護していたことの裏付けです)'
        );

        $this->assertSame(0, DatabaseResolutionSentinel::attempts());
    }

    // ------------------------------------------------------------------
    // ケース5: 登録漏れなら、ケース1と同じアサーションが実際に失敗する
    // ------------------------------------------------------------------
    public function test_registration_omission_makes_the_rejection_assertion_actually_fail(): void
    {
        // ケース1と全く同じアサーション(RuntimeExceptionが投げられ、メッセージに
        // [pgsql]を含む)を、ガード未登録のブート結果に対して実行し、
        // PHPUnitのアサーション自体が失敗する(ExpectationFailedExceptionを投げる)
        // ことを確認する。これにより「ガード登録がない場合、拒否を期待するテストは
        // 実際に失敗する」ことを、比較ではなくアサーション自体の失敗として裏付ける。
        $dir = $this->makeFakeApplication($this->dangerousPgsqlDatabaseConfig());

        $result = $this->bootFakeApplication($dir, registerGuard: false);

        $this->expectException(ExpectationFailedException::class);

        // ここから先はケース1と同一のアサーション。ガード未登録により
        // $result['exception'] は null のはずなので、assertInstanceOf が失敗する。
        self::assertInstanceOf(\RuntimeException::class, $result['exception']);
    }

    // ------------------------------------------------------------------
    // ケース6: 防壁(DatabaseResolutionSentinel)自体が、DB接続処理より前に止める
    // ------------------------------------------------------------------
    public function test_database_resolution_sentinel_blocks_explicit_db_resolution(): void
    {
        // このケースだけは、ガードや危険な設定とは無関係に、防壁自体の動作を
        // 単独で検証する。安全な設定の最小アプリを作り、DatabaseResolutionSentinelを
        // 設置したうえで、意図的に 'db' サービスの解決を要求する。
        $dir = $this->makeFakeApplication($this->safeSqliteDatabaseConfig());

        $app = $this->createFakeApplication($dir);

        $this->assertSame(0, DatabaseResolutionSentinel::attempts());

        $caught = null;

        try {
            // bootstrap()すら呼ばず、コンテナに対して直接'db'解決を要求する。
            // 実際のbinding(DatabaseServiceProviderが登録するクロージャ)へ
            // 進む前に、防壁のbeforeResolvingコールバックが先に発火するはず。
            $app->make('db');
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            DatabaseResolutionAttemptedException::class,
            $caught,
            '防壁が db の解決を検知して例外を投げるはずです。'
        );
        $this->assertStringContainsString('[db]', $caught->getMessage());
        $this->assertSame(1, DatabaseResolutionSentinel::attempts());
        $this->assertSame(['db'], DatabaseResolutionSentinel::$attemptedAbstracts);
    }

    // ------------------------------------------------------------------
    // ケース7: 外部パス指定を参照・変更しないこと(通常の設定読み込み)
    // ------------------------------------------------------------------
    public function test_normal_config_loading_ignores_and_does_not_modify_externally_pointed_paths(): void
    {
        [$externalDir, $externalFiles] = $this->makeExternalPathDecoy();
        $this->pointAmbientPathEnvAt($externalDir);

        $dir = $this->makeFakeApplication($this->safeSqliteDatabaseConfig());

        $result = $this->bootFakeApplication($dir, registerGuard: true);

        // 通常の(設定キャッシュを作っていない)設定読み込みが、外部パスの干渉が
        // あっても従来どおり動くこと。
        $this->assertNull(
            $result['exception'],
            $result['exception'] !== null ? '想定外の例外: '.$result['exception']->getMessage() : ''
        );
        $this->assertSame(['register', 'boot'], RecordingServiceProvider::$log);
        $this->assertSame(0, DatabaseResolutionSentinel::attempts());

        $this->assertFakeAppPathsArePinnedInside($dir, $result['app']);

        // 外部パス役に登録した(実在しない)ダミーパッケージのProviderが混入して
        // いないこと。config('app.providers')とbootstrap/providers.phpの両方に
        // RecordingServiceProviderを載せているため、重複要素はあり得る
        // (Illuminate\Foundation\Application::register()が同一クラスの二重登録を
        // 無視するため無害)。重複除去したうえで、想定した2つのProviderの集合と
        // 一致することだけを確認する。
        $providers = $result['app']->make('config')->get('app.providers');
        $uniqueProviders = array_values(array_unique($providers));
        sort($uniqueProviders);
        $expected = [FilesystemServiceProvider::class, RecordingServiceProvider::class];
        sort($expected);
        $this->assertSame(
            $expected,
            $uniqueProviders,
            '外部パス役由来のProviderが混入しています: '.implode(',', $providers)
        );

        $this->assertExternalPathDecoyUnchanged($externalFiles);
    }

    // ------------------------------------------------------------------
    // ケース8: 外部パス指定を参照・変更しないこと(意図した設定キャッシュ読み込み)
    // ------------------------------------------------------------------
    public function test_dangerous_config_cache_rejection_ignores_externally_pointed_paths(): void
    {
        [$externalDir, $externalFiles] = $this->makeExternalPathDecoy();
        $this->pointAmbientPathEnvAt($externalDir);

        $this->setEnv('DB_CONNECTION', 'sqlite');
        $this->setEnv('DB_DATABASE', ':memory:');

        $cachedConfig = [
            'app' => [
                'name' => 'GuardIntegrationFakeApp',
                'env' => 'testing',
                'debug' => false,
                'timezone' => 'UTC',
                'key' => null,
                'providers' => [RecordingServiceProvider::class],
            ],
            'database' => $this->dangerousPgsqlDatabaseConfig(),
        ];

        $dir = $this->makeFakeApplication($this->safeSqliteDatabaseConfig(), cachedConfigArray: $cachedConfig);

        $result = $this->bootFakeApplication($dir, registerGuard: true);

        // 外部パス役の設定キャッシュ(存在すれば安全な内容)ではなく、$dir自身の
        // 設定キャッシュ(危険な内容)が使われ、ガードが拒否することを確認する。
        $this->assertInstanceOf(\RuntimeException::class, $result['exception']);
        $this->assertStringContainsString('[pgsql]', $result['exception']->getMessage());
        $this->assertNotInstanceOf(DatabaseResolutionAttemptedException::class, $result['exception']);
        $this->assertTrue(
            $result['app']->bound('config_loaded_from_cache') && $result['app']->make('config_loaded_from_cache'),
            '設定キャッシュ経由で読み込まれたことを確認できませんでした。'
        );
        $this->assertSame(
            $dir.'/bootstrap/cache/config.php',
            $result['app']->getCachedConfigPath(),
            '実効設定キャッシュパスが最小アプリ内に固定されていません。'
        );

        $this->assertSame([], RecordingServiceProvider::$log);
        $this->assertSame(0, DatabaseResolutionSentinel::attempts());

        $this->assertExternalPathDecoyUnchanged($externalFiles);
    }

    // ------------------------------------------------------------------
    // ヘルパー
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function safeSqliteDatabaseConfig(): array
    {
        return [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
            ],
        ];
    }

    /**
     * ダミーのPostgreSQL設定。開発用DBの実際のホスト・認証情報は一切含まない。
     * ホストにRFC5737で文書化用に予約されたIPアドレスを使っているが、これは
     * 「実在するホスト名/IPを書かない」ための配慮に過ぎず、それ自体が安全性の
     * 保証ではない(このIPへ実際に接続しようとするコードが万一あれば、接続の
     * 成否はネットワーク環境に依存する)。この検証での実質的な安全性は、
     * config/app.phpのProvider最小化と、DatabaseResolutionSentinelという
     * 能動的な防壁の両方に置いている。
     *
     * @return array<string, mixed>
     */
    private function dangerousPgsqlDatabaseConfig(): array
    {
        return [
            'default' => 'pgsql',
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
                'pgsql' => [
                    'driver' => 'pgsql',
                    'host' => '203.0.113.1', // RFC5737 TEST-NET-3の予約アドレス(実在ホストではない)
                    'port' => '5432',
                    'database' => 'dummy_database_placeholder',
                    'username' => 'dummy_user_placeholder',
                    'password' => 'dummy_password_placeholder',
                ],
            ],
        ];
    }

    /**
     * 一時ディレクトリに最小のLaravelアプリの骨組みを作り、そのベースパスを返す。
     * 本物のbackend/以下のファイルは一切参照・コピーしない。
     *
     * config/app.php の 'providers' は検証用Provider(RecordingServiceProvider)のみ。
     * Laravel標準のデフォルトProvider群は含めない(このファイル冒頭のクラスdocを参照)。
     *
     * @param  array<string, mixed>  $databaseConfig  config/database.phpの内容
     * @param  array<string, mixed>|null  $cachedConfigArray  指定時はbootstrap/cache/config.php
     *                                                        を作る(設定キャッシュ由来の拒否ケース用)。
     */
    private function makeFakeApplication(array $databaseConfig, ?array $cachedConfigArray = null): string
    {
        $dir = sys_get_temp_dir().'/opds-guard-it-'.bin2hex(random_bytes(6));

        mkdir($dir, 0700, true);
        mkdir($dir.'/bootstrap/cache', 0700, true);
        mkdir($dir.'/config', 0700, true);
        // COMPOSER_VENDOR_DIRの固定先。composer/installed.jsonを持たない空ディレクトリ
        // にすることで、Illuminate\Foundation\PackageManifestが実プロジェクトの
        // vendor/composer/installed.jsonへは一切アクセスしないようにする。
        mkdir($dir.'/vendor-empty', 0700, true);

        $this->tempDirs[] = $dir;

        file_put_contents($dir.'/bootstrap/app.php', <<<'PHP'
            <?php

            use Illuminate\Foundation\Application;

            return Application::configure(basePath: dirname(__DIR__))->create();

            PHP
        );

        file_put_contents($dir.'/bootstrap/providers.php', sprintf(
            "<?php\n\nreturn [\\%s::class];\n",
            RecordingServiceProvider::class
        ));

        file_put_contents($dir.'/config/app.php', sprintf(
            <<<'PHP'
                <?php

                return [
                    'name' => 'GuardIntegrationFakeApp',
                    'env' => 'testing',
                    'debug' => false,
                    'timezone' => 'UTC',
                    'key' => null,
                    // Laravel標準のデフォルトProvider群(DatabaseServiceProviderを含む)を
                    // 意図的に含めない、検証専用の最小構成。
                    // FilesystemServiceProvider('files'を束縛)だけは例外的に含める。
                    // Illuminate\Foundation\Configuration\ApplicationBuilder::withEvents()が
                    // config('app.providers')とは無関係に、bootstrap/app.php生成直後から
                    // Illuminate\Foundation\Support\Providers\EventServiceProviderの登録を
                    // $app->booting()コールバックとして仕込んでおり、そのregister()が
                    // events:cache状態の確認のため'files'を要求するため、これが無いと
                    // (DBとは無関係な理由で)bootstrap自体が失敗する。DBには一切関与しない。
                    'providers' => [
                        \Illuminate\Filesystem\FilesystemServiceProvider::class,
                        \%s::class,
                    ],
                ];

                PHP,
            RecordingServiceProvider::class
        ));

        file_put_contents(
            $dir.'/config/database.php',
            "<?php\n\nreturn ".var_export($databaseConfig, true).";\n"
        );

        if ($cachedConfigArray !== null) {
            file_put_contents(
                $dir.'/bootstrap/cache/config.php',
                "<?php\n\nreturn ".var_export($cachedConfigArray, true).";\n"
            );
        }

        return $dir;
    }

    /**
     * $dir/bootstrap/app.php から最小アプリを作り、DatabaseResolutionSentinelを
     * bootstrap開始前に設置したうえでbootstrapする。tests/TestCase.php::
     * createApplication() と同じ TestDatabaseGuard::registerHook() を
     * (registerGuardがtrueの場合のみ)使う。
     *
     * @return array{app: Application, exception: Throwable|null}
     */
    private function bootFakeApplication(string $dir, bool $registerGuard): array
    {
        $app = $this->createFakeApplication($dir);

        if ($registerGuard) {
            TestDatabaseGuard::registerHook($app, null);
        }

        try {
            $app->make(Kernel::class)->bootstrap();

            return ['app' => $app, 'exception' => null];
        } catch (Throwable $e) {
            return ['app' => $app, 'exception' => $e];
        }
    }

    /**
     * $dir/bootstrap/app.php から最小アプリのApplicationインスタンスを作る。
     *
     * pinFakeAppPaths()によるキャッシュ・vendorパスの固定と、
     * DatabaseResolutionSentinelの設置を、アプリの生成(requireそのもの)より
     * 前・bootstrap()より前に行う。両方とも「全ケース共通で、アプリの生成前に
     * 効いている」ことを保証するための一本化した入口。
     */
    private function createFakeApplication(string $dir): Application
    {
        $this->pinFakeAppPaths($dir);

        /** @var Application $app */
        $app = require $dir.'/bootstrap/app.php';

        DatabaseResolutionSentinel::install($app);

        return $app;
    }

    /**
     * Illuminate\Foundation\Application が Illuminate\Support\Env::get() 経由で
     * 読む、設定キャッシュ・サービスキャッシュ・パッケージキャッシュ・
     * ルートキャッシュ・イベントキャッシュの各パスと、パッケージ自動検出の
     * vendorディレクトリを、$dir 内のパスへ固定する。putenv()・$_ENV・$_SERVERの
     * 3箇所すべてに同じ値を設定する(このファイル冒頭のクラスdocに読み取り順の
     * 根拠を記載)。元の値はtearDown()のrestoreEnv()で復元する。
     */
    private function pinFakeAppPaths(string $dir): void
    {
        $this->setEnv('APP_CONFIG_CACHE', $dir.'/bootstrap/cache/config.php');
        $this->setEnv('APP_SERVICES_CACHE', $dir.'/bootstrap/cache/services.php');
        $this->setEnv('APP_PACKAGES_CACHE', $dir.'/bootstrap/cache/packages.php');
        $this->setEnv('APP_ROUTES_CACHE', $dir.'/bootstrap/cache/routes-v7.php');
        $this->setEnv('APP_EVENTS_CACHE', $dir.'/bootstrap/cache/events.php');
        $this->setEnv('COMPOSER_VENDOR_DIR', $dir.'/vendor-empty');
    }

    /**
     * 「外部から既に別のパス指定が入っていた」状況を再現するための、テスト自身が
     * 作る別の一時ディレクトリ(実環境のファイルは一切使わない)。5つのキャッシュ
     * ファイルと、ダミーパッケージを1つ含むvendor/composer/installed.jsonを
     * 用意する。tearDown()で削除対象に登録する。
     *
     * @return array{0: string, 1: array<string, string>} [外部ディレクトリのパス,
     *                                                    {ファイルパス: 期待される中身}の一覧]
     */
    private function makeExternalPathDecoy(): array
    {
        $externalDir = sys_get_temp_dir().'/opds-guard-external-'.bin2hex(random_bytes(6));

        mkdir($externalDir.'/bootstrap/cache', 0700, true);
        mkdir($externalDir.'/vendor/composer', 0700, true);

        $this->tempDirs[] = $externalDir;

        $marker = 'external-decoy-do-not-read-'.bin2hex(random_bytes(6));
        $markerContent = "<?php\n\nreturn ['external_decoy' => true, 'marker' => '{$marker}'];\n";

        $files = [
            $externalDir.'/bootstrap/cache/config.php' => $markerContent,
            $externalDir.'/bootstrap/cache/services.php' => $markerContent,
            $externalDir.'/bootstrap/cache/packages.php' => $markerContent,
            $externalDir.'/bootstrap/cache/routes-v7.php' => $markerContent,
            $externalDir.'/bootstrap/cache/events.php' => $markerContent,
        ];

        foreach ($files as $path => $content) {
            file_put_contents($path, $content);
        }

        $installedJson = json_encode([
            'packages' => [[
                'name' => 'external-decoy/fake-package',
                'extra' => ['laravel' => ['providers' => ['External\\Decoy\\FakeProvider']]],
            ]],
        ]);
        file_put_contents($externalDir.'/vendor/composer/installed.json', $installedJson);
        $files[$externalDir.'/vendor/composer/installed.json'] = $installedJson;

        return [$externalDir, $files];
    }

    /**
     * $externalDir を指すよう、6つの環境変数すべてを(putenv・$_ENV・$_SERVERの
     * 3箇所とも)設定する。「実環境で既にこれらの変数が外部を指していた」状況の
     * 再現であり、この直後に makeFakeApplication()/bootFakeApplication() を
     * 呼んでも pinFakeAppPaths() が上書きするため、実際には参照されないはずである
     * ことを確認するために使う。
     */
    private function pointAmbientPathEnvAt(string $externalDir): void
    {
        $this->setEnv('APP_CONFIG_CACHE', $externalDir.'/bootstrap/cache/config.php');
        $this->setEnv('APP_SERVICES_CACHE', $externalDir.'/bootstrap/cache/services.php');
        $this->setEnv('APP_PACKAGES_CACHE', $externalDir.'/bootstrap/cache/packages.php');
        $this->setEnv('APP_ROUTES_CACHE', $externalDir.'/bootstrap/cache/routes-v7.php');
        $this->setEnv('APP_EVENTS_CACHE', $externalDir.'/bootstrap/cache/events.php');
        $this->setEnv('COMPOSER_VENDOR_DIR', $externalDir.'/vendor');
    }

    /**
     * $app の実効キャッシュパスが、外部パス役ではなく $dir 内に固定されている
     * ことを確認する。
     */
    private function assertFakeAppPathsArePinnedInside(string $dir, Application $app): void
    {
        $this->assertSame($dir.'/bootstrap/cache/config.php', $app->getCachedConfigPath());
        $this->assertSame($dir.'/bootstrap/cache/services.php', $app->getCachedServicesPath());
        $this->assertSame($dir.'/bootstrap/cache/packages.php', $app->getCachedPackagesPath());
        $this->assertSame($dir.'/bootstrap/cache/routes-v7.php', $app->getCachedRoutesPath());
        $this->assertSame($dir.'/bootstrap/cache/events.php', $app->getCachedEventsPath());
    }

    /**
     * makeExternalPathDecoy() が用意したファイル群が、一切変更されていない
     * (書き込まれていない)ことを確認する。
     *
     * @param  array<string, string>  $expectedFiles
     */
    private function assertExternalPathDecoyUnchanged(array $expectedFiles): void
    {
        foreach ($expectedFiles as $path => $expectedContent) {
            $this->assertFileExists($path, "外部パス役のファイルが消えています: {$path}");
            $this->assertSame(
                $expectedContent,
                file_get_contents($path),
                "外部パス役のファイルが変更されています: {$path}"
            );
        }
    }

    /**
     * putenv()・$_ENV・$_SERVERの3箇所すべてに同じ値を設定する。
     *
     * Illuminate\Support\Env::getRepository()が使うリーダーの既定順は
     * ServerConstAdapter($_SERVER)→EnvConstAdapter($_ENV)→PutenvAdapter(getenv())で、
     * 最初に値が見つかった方が勝つ(vendor/vlucas/phpdotenv/src/Repository/
     * Adapter/MultiReader::read())。putenv()だけを書き換えても、$_SERVER/$_ENVに
     * 既に値が入っていればそちらが優先されてしまうため、3箇所とも揃える。
     */
    private function setEnv(string $name, string $value): void
    {
        if (! array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = [
                'putenv' => getenv($name),
                'env' => array_key_exists($name, $_ENV) ? $_ENV[$name] : self::UNSET,
                'server' => array_key_exists($name, $_SERVER) ? $_SERVER[$name] : self::UNSET,
            ];
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function restoreEnv(string $name): void
    {
        $original = $this->envBackup[$name] ?? null;

        if ($original === null) {
            return;
        }

        if ($original['putenv'] === false) {
            putenv($name);
        } else {
            putenv("{$name}={$original['putenv']}");
        }

        if ($original['env'] === self::UNSET) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $original['env'];
        }

        if ($original['server'] === self::UNSET) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $original['server'];
        }
    }

    private function removeDirectoryRecursively(string $dir): void
    {
        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectoryRecursively($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
