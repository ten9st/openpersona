<?php

namespace Tests\Unit\Support;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabaseGuard;

/**
 * TestDatabaseGuard::assertSafe() 自体(接続名の選択ロジックを含む)を、
 * Laravelアプリケーションを一切起動せずに検証する。
 *
 * - Illuminate\Contracts\Foundation\Application は Mockery でモックする。
 *   make('config') 以外の呼び出し(例: make('db')・DB接続の解決)が発生した場合は、
 *   Mockeryが未設定の呼び出しとして例外を投げ、テストが失敗する
 *   (NoMatchingExpectationException / BadMethodCallException)。これにより
 *   「DBサービスの解決が発生したら検証が失敗する」ことを保証する。
 * - 設定側は、Laravelが実際に使う Illuminate\Config\Repository をそのまま
 *   プレーンな配列で組み立てて使う(config()ヘルパーやコンテナ経由の解決はしない)。
 * - このクラスは PHPUnit\Framework\TestCase を直接継承し、Illuminate\Foundation\Testing\TestCase
 *   / Tests\TestCase は使わない。DBへは一切接続しない。
 *
 * 実行方法(Laravelのbootstrap・拡張・他テストを一切読み込まない独立実行):
 *   cd backend
 *   vendor/bin/phpunit --no-configuration --bootstrap=vendor/autoload.php \
 *     tests/Unit/Support/TestDatabaseGuardAssertSafeTest.php
 */
class TestDatabaseGuardAssertSafeTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const SAFE_SQLITE = ['driver' => 'sqlite', 'database' => ':memory:'];

    private const DANGEROUS_PGSQL = [
        'driver' => 'pgsql',
        'database' => 'openpersona',
        'host' => 'db',
    ];

    /**
     * @param  array<string, array<string, mixed>>  $connections
     */
    private function configWith(array $connections, string $default): Repository
    {
        return new Repository([
            'database' => [
                'default' => $default,
                'connections' => $connections,
            ],
        ]);
    }

    /**
     * make('config') 以外が呼ばれたら失敗する、最小限のApplicationモックを作る。
     */
    private function appExposingOnlyConfig(Repository $config): Application
    {
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('make')->once()->with('config')->andReturn($config);

        return $app;
    }

    /**
     * RefreshDatabase等が参照する $connectionsToTransact を持つ、テスト対象クラス相当の
     * ダミーオブジェクトを作る。$value をそのままプロパティへ入れる(型は検証しない)。
     */
    private function fakeTestCaseWithConnectionsToTransact(mixed $value): object
    {
        return new class($value)
        {
            protected $connectionsToTransact;

            public function __construct($value)
            {
                $this->connectionsToTransact = $value;
            }
        };
    }

    // ------------------------------------------------------------------
    // 1. デフォルトが危険、追加接続が安全 → 拒否
    // ------------------------------------------------------------------
    public function test_rejects_when_default_connection_is_dangerous_even_if_additional_is_safe(): void
    {
        $config = $this->configWith([
            'pgsql' => self::DANGEROUS_PGSQL,
            'sqlite' => self::SAFE_SQLITE,
        ], default: 'pgsql');

        $app = $this->appExposingOnlyConfig($config);
        $testCase = $this->fakeTestCaseWithConnectionsToTransact(['sqlite']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\[pgsql\]/');

        TestDatabaseGuard::assertSafe($app, $testCase);
    }

    // ------------------------------------------------------------------
    // 2. デフォルトが安全、追加接続が危険 → 拒否
    // ------------------------------------------------------------------
    public function test_rejects_when_additional_connection_is_dangerous_even_if_default_is_safe(): void
    {
        $config = $this->configWith([
            'sqlite' => self::SAFE_SQLITE,
            'pgsql' => self::DANGEROUS_PGSQL,
        ], default: 'sqlite');

        $app = $this->appExposingOnlyConfig($config);
        $testCase = $this->fakeTestCaseWithConnectionsToTransact(['pgsql']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\[pgsql\]/');

        TestDatabaseGuard::assertSafe($app, $testCase);
    }

    // ------------------------------------------------------------------
    // 3. 両方安全 → 許可
    // ------------------------------------------------------------------
    public function test_allows_when_default_and_additional_connections_are_both_safe(): void
    {
        $config = $this->configWith([
            'sqlite' => self::SAFE_SQLITE,
            'sqlite_alias' => self::SAFE_SQLITE,
        ], default: 'sqlite');

        $app = $this->appExposingOnlyConfig($config);
        $testCase = $this->fakeTestCaseWithConnectionsToTransact(['sqlite_alias']);

        TestDatabaseGuard::assertSafe($app, $testCase); // 例外が出なければ成功

        $this->addToAssertionCount(1);
    }

    // ------------------------------------------------------------------
    // 4. 追加接続指定なし・空配列でもデフォルトを検査
    // ------------------------------------------------------------------
    public function test_checks_default_connection_when_test_case_is_null(): void
    {
        $config = $this->configWith(['pgsql' => self::DANGEROUS_PGSQL], default: 'pgsql');
        $app = $this->appExposingOnlyConfig($config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\[pgsql\]/');

        TestDatabaseGuard::assertSafe($app, null);
    }

    public function test_checks_default_connection_when_connections_to_transact_is_empty_array(): void
    {
        $config = $this->configWith(['pgsql' => self::DANGEROUS_PGSQL], default: 'pgsql');
        $app = $this->appExposingOnlyConfig($config);
        $testCase = $this->fakeTestCaseWithConnectionsToTransact([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\[pgsql\]/');

        TestDatabaseGuard::assertSafe($app, $testCase);
    }

    // ------------------------------------------------------------------
    // 5. nullの接続名をデフォルトとして処理
    // ------------------------------------------------------------------
    public function test_null_connection_name_is_treated_as_default_and_passes_when_default_is_safe(): void
    {
        $config = $this->configWith(['sqlite' => self::SAFE_SQLITE], default: 'sqlite');
        $app = $this->appExposingOnlyConfig($config);
        $testCase = $this->fakeTestCaseWithConnectionsToTransact([null]);

        TestDatabaseGuard::assertSafe($app, $testCase);

        $this->addToAssertionCount(1);
    }

    public function test_null_connection_name_is_treated_as_default_and_fails_when_default_is_dangerous(): void
    {
        $config = $this->configWith(['pgsql' => self::DANGEROUS_PGSQL], default: 'pgsql');
        $app = $this->appExposingOnlyConfig($config);
        $testCase = $this->fakeTestCaseWithConnectionsToTransact([null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\[pgsql\]/');

        TestDatabaseGuard::assertSafe($app, $testCase);
    }

    // ------------------------------------------------------------------
    // 6. 接続の未定義・不正な型 → 拒否
    // ------------------------------------------------------------------
    public function test_undefined_additional_connection_name_is_rejected(): void
    {
        $config = $this->configWith(['sqlite' => self::SAFE_SQLITE], default: 'sqlite');
        $app = $this->appExposingOnlyConfig($config);
        $testCase = $this->fakeTestCaseWithConnectionsToTransact(['no_such_connection']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\[no_such_connection\]/');

        TestDatabaseGuard::assertSafe($app, $testCase);
    }

    public function test_empty_string_connection_name_is_rejected(): void
    {
        $config = $this->configWith(['sqlite' => self::SAFE_SQLITE], default: 'sqlite');
        $app = $this->appExposingOnlyConfig($config);
        $testCase = $this->fakeTestCaseWithConnectionsToTransact(['']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/connectionsToTransact/');

        TestDatabaseGuard::assertSafe($app, $testCase);
    }

    public function test_non_string_connection_name_is_rejected(): void
    {
        $config = $this->configWith(['sqlite' => self::SAFE_SQLITE], default: 'sqlite');
        $app = $this->appExposingOnlyConfig($config);
        $testCase = $this->fakeTestCaseWithConnectionsToTransact([123]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/connectionsToTransact/');

        TestDatabaseGuard::assertSafe($app, $testCase);
    }

    public function test_invalid_connection_name_error_message_does_not_leak_the_value(): void
    {
        $dummySecret = 'dummy-secret-'.bin2hex(random_bytes(6));

        $config = $this->configWith(['sqlite' => self::SAFE_SQLITE], default: 'sqlite');
        $app = $this->appExposingOnlyConfig($config);
        // 不正な型(配列)の中にダミー秘密情報を仕込む。メッセージにはget_debug_type()による
        // 型名('array')だけが出て、中身の値は出ないことを確認する。
        $testCase = $this->fakeTestCaseWithConnectionsToTransact([['leaked' => $dummySecret]]);

        try {
            TestDatabaseGuard::assertSafe($app, $testCase);
            $this->fail('例外が発生するはずです。');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString($dummySecret, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 重複除去: デフォルトと追加接続が同名でも1回しか検査しない(config->get呼び出し回数で確認)
    // ------------------------------------------------------------------
    public function test_duplicate_connection_names_are_checked_only_once(): void
    {
        $config = Mockery::mock(Repository::class);
        $config->shouldReceive('get')->once()->with('database.default')->andReturn('sqlite');
        $config->shouldReceive('get')->once()->with('database.connections.sqlite')->andReturn(self::SAFE_SQLITE);

        $app = Mockery::mock(Application::class);
        $app->shouldReceive('make')->once()->with('config')->andReturn($config);

        $testCase = $this->fakeTestCaseWithConnectionsToTransact(['sqlite', null]);

        TestDatabaseGuard::assertSafe($app, $testCase);

        $this->addToAssertionCount(1);
    }
}
