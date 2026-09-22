<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabaseGuard;

/**
 * TestDatabaseGuard::rejectionReason() の純粋な判定ロジックのみを検証する。
 *
 * - Laravelを一切起動しない(このクラスは PHPUnit\Framework\TestCase を直接継承する。
 *   Illuminate\Foundation\Testing\TestCase / Tests\TestCase は使わない)。
 * - DBへは一切接続しない。設定配列を直接渡して判定結果(文字列 or null)を見るだけ。
 * - 判定ロジックを本テストへ複製せず、実際の TestDatabaseGuard::rejectionReason() を
 *   そのまま呼び出す。
 *
 * 実行方法(Laravelのbootstrap・拡張・他テストを一切読み込まない独立実行):
 *   cd backend
 *   vendor/bin/phpunit --no-configuration --bootstrap=vendor/autoload.php \
 *     tests/Unit/Support/TestDatabaseGuardTest.php
 */
class TestDatabaseGuardTest extends TestCase
{
    public function test_sqlite_memory_is_allowed(): void
    {
        $this->assertNull(TestDatabaseGuard::rejectionReason([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]));
    }

    public function test_file_based_sqlite_is_rejected(): void
    {
        $reason = TestDatabaseGuard::rejectionReason([
            'driver' => 'sqlite',
            'database' => '/var/www/backend/database/database.sqlite',
        ]);

        $this->assertNotNull($reason);
        $this->assertStringContainsString(':memory:', $reason);
    }

    public function test_other_driver_is_rejected(): void
    {
        foreach (['pgsql', 'mysql', 'mariadb', 'sqlsrv'] as $driver) {
            $reason = TestDatabaseGuard::rejectionReason([
                'driver' => $driver,
                'database' => ':memory:', // sqlite以外がdatabaseだけ':memory:'を名乗っても許可しない
                'host' => '127.0.0.1',
            ]);

            $this->assertNotNull($reason, "driver={$driver} は拒否されるべきです");
            $this->assertStringContainsString('sqlite', $reason);
        }
    }

    public function test_url_overriding_to_a_dangerous_connection_is_rejected(): void
    {
        // 生設定は一見安全(sqlite/:memory:)だが、urlキーがpostgresを指しているため
        // 実効設定(ConfigurationUrlParserの解決結果)はpgsqlになる。
        $reason = TestDatabaseGuard::rejectionReason([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'url' => 'postgres://dummyuser:dummy-secret-value@dummy-host:5432/dummy_db',
        ]);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('sqlite', $reason);
    }

    public function test_url_overriding_to_a_real_sqlite_file_is_rejected(): void
    {
        $reason = TestDatabaseGuard::rejectionReason([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'url' => 'sqlite:///var/www/backend/database/database.sqlite',
        ]);

        $this->assertNotNull($reason);
        $this->assertStringContainsString(':memory:', $reason);
    }

    public function test_malformed_url_is_rejected(): void
    {
        // parse_url()が処理できない不正な文字列。ConfigurationUrlParserが
        // InvalidArgumentExceptionを投げるが、ガードは例外を外へ漏らさず拒否理由に変換する。
        $reason = TestDatabaseGuard::rejectionReason([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'url' => 'http:///:not-a-valid-url:::',
        ]);

        $this->assertNotNull($reason);
    }

    public function test_missing_driver_key_is_rejected(): void
    {
        $reason = TestDatabaseGuard::rejectionReason([
            'database' => ':memory:',
        ]);

        $this->assertNotNull($reason);
    }

    public function test_missing_database_key_is_rejected(): void
    {
        $reason = TestDatabaseGuard::rejectionReason([
            'driver' => 'sqlite',
        ]);

        $this->assertNotNull($reason);
    }

    public function test_non_string_database_value_is_rejected(): void
    {
        $reason = TestDatabaseGuard::rejectionReason([
            'driver' => 'sqlite',
            'database' => null,
        ]);

        $this->assertNotNull($reason);
    }

    public function test_read_write_split_configuration_is_rejected_even_if_it_looks_like_memory_sqlite(): void
    {
        $reason = TestDatabaseGuard::rejectionReason([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read' => ['host' => 'dummy-read-host'],
            'write' => ['host' => 'dummy-write-host'],
        ]);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('read/write', $reason);
    }

    public function test_read_write_split_configuration_is_rejected_when_only_write_is_present(): void
    {
        $reason = TestDatabaseGuard::rejectionReason([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'write' => ['host' => 'dummy-write-host'],
        ]);

        $this->assertNotNull($reason);
    }

    public function test_rejection_reason_never_contains_the_dummy_secret_from_raw_config(): void
    {
        $dummySecret = 'sekret-dummy-'.bin2hex(random_bytes(8));

        $configs = [
            [
                'driver' => 'pgsql',
                'database' => 'openpersona',
                'host' => 'db',
                'username' => 'openpersona',
                'password' => $dummySecret,
            ],
            [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'url' => "postgres://openpersona:{$dummySecret}@db:5432/openpersona",
            ],
            [
                'driver' => 'sqlite',
                'database' => '/var/www/backend/storage/'.$dummySecret.'.sqlite',
            ],
        ];

        foreach ($configs as $config) {
            $reason = TestDatabaseGuard::rejectionReason($config);

            $this->assertNotNull($reason);
            $this->assertStringNotContainsString($dummySecret, $reason);
        }
    }
}
