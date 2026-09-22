<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;

/**
 * テスト実行時に、DBの接続・初期化(RefreshDatabase等によるmigrate:fresh)より前に、
 * 実効的な接続設定がsqliteの:memory:であることを確認するガード。
 *
 * - DBへは一切接続しない。config()経由で読み取った設定配列を検査するのみ。
 * - 判定ロジック(rejectionReason)はLaravelアプリケーション(コンテナ・config()ヘルパー
 *   ・DB接続)に一切依存しない純粋関数として切り出してあり、
 *   tests/Unit/Support/TestDatabaseGuardTest.php でLaravelを起動せずに単体テストできる。
 * - 接続文字列(DB_URL)による上書きは、Laravel自身が接続確立時に使う
 *   Illuminate\Support\ConfigurationUrlParser をそのまま使って解決する
 *   (同じ判定ロジックを再実装しない)。
 * - 例外メッセージには接続名と拒否理由の分類のみを含め、URL・パスワード・
 *   設定配列全体などの値は一切含めない。
 */
class TestDatabaseGuard
{
    /**
     * $app が確定した実効設定を検査し、許可されない接続があれば例外を投げて停止する。
     *
     * 検査対象は次の和集合(重複除去済み):
     * - database.default(migrate:freshが対象接続を明示しない場合に使う接続。常に検査する)
     * - $testCase の $connectionsToTransact プロパティに指定された追加接続
     *   (Illuminate\Foundation\Testing\RefreshDatabase::connectionsToTransact() が
     *   参照するのと同じプロパティ)
     *
     * $connectionsToTransact を指定しても database.default の検査は省略しない。
     * 要素がnullの場合はLaravelの実際の解決規則(DatabaseManager::connection()の
     * `enum_value($name) ?: $this->getDefaultConnection()`)に合わせ、デフォルト接続を
     * 指しているものとして扱う。それ以外(空文字・文字列以外の型)は、Laravelの
     * 実際の解決規則とは異なり、安全側に倒して即座に拒否する
     * (enum型の接続名など、このコードベースで使われていない解決規則までは追随しない)。
     *
     * 呼び出し元(tests/TestCase.php)は、Illuminate\Foundation\Bootstrap\LoadConfiguration
     * のbootstrap完了直後(RegisterProviders/BootProvidersより前)にこれを呼ぶ契約とする。
     *
     * @param  object|null  $testCase  現在実行中のテストインスタンス。省略時は
     *                                 database.default のみを検査する。
     *
     * @throws RuntimeException 許可されない実効設定、または不正な接続名指定を検出した場合
     */
    public static function assertSafe(Application $app, ?object $testCase = null): void
    {
        $config = $app->make('config');

        $default = (string) $config->get('database.default');

        $connectionNames = [$default];

        foreach (self::additionalConnectionValues($testCase) as $value) {
            if ($value === null) {
                $connectionNames[] = $default;

                continue;
            }

            if (! is_string($value) || $value === '') {
                throw self::rejectedInvalidConnectionName($value);
            }

            $connectionNames[] = $value;
        }

        foreach (array_values(array_unique($connectionNames)) as $name) {
            $rawConfig = $config->get("database.connections.{$name}");

            if (! is_array($rawConfig)) {
                throw self::rejected($name, '接続設定が配列として定義されていません(未定義または不正な形式です)。');
            }

            $reason = self::rejectionReason($rawConfig);

            if ($reason !== null) {
                throw self::rejected($name, $reason);
            }
        }
    }

    /**
     * 単一の接続設定(config('database.connections.{name}')が返す生の配列)を検査し、
     * 許可される場合はnull、拒否する場合は理由の文字列を返す。
     *
     * 許可条件(両方を満たす場合のみ):
     * - 実効driverが厳密に 'sqlite' であること
     * - 実効databaseが厳密に ':memory:' であること
     *
     * 実効値は Illuminate\Support\ConfigurationUrlParser::parseConfiguration() を通して
     * 求める。これによりDB_URL等の接続文字列でdriver/databaseが上書きされるケースも
     * 正しく検出できる(生の 'driver'/'database' キーだけを見ると見逃す)。
     *
     * read/write分離構成(config('database.connections.X.read'/'write'))は、今回の
     * ガードが対応しない複雑な構成として一律拒否する。
     *
     * この関数はLaravelアプリケーション・config()ヘルパー・DB接続のいずれにも依存しない
     * 純粋関数であり、配列を渡すだけでLaravelを起動せずに検証できる。
     *
     * @param  array<string, mixed>  $rawConfig
     */
    public static function rejectionReason(array $rawConfig): ?string
    {
        if (array_key_exists('read', $rawConfig) || array_key_exists('write', $rawConfig)) {
            return 'read/write分離構成は今回のガードが対応していないため拒否します。';
        }

        try {
            $effective = (new ConfigurationUrlParser)->parseConfiguration($rawConfig);
        } catch (\InvalidArgumentException) {
            return '接続URLの形式が不正なため実効設定を解決できません。';
        }

        if (array_key_exists('read', $effective) || array_key_exists('write', $effective)) {
            return 'read/write分離構成は今回のガードが対応していないため拒否します。';
        }

        $driver = $effective['driver'] ?? null;
        $database = $effective['database'] ?? null;

        if ($driver !== 'sqlite') {
            return "driverが 'sqlite' ではありません(接続URLによる上書きを含めて解決した結果です)。";
        }

        if ($database !== ':memory:') {
            return "databaseが ':memory:' ではありません(接続URLによる上書きを含めて解決した結果です)。";
        }

        return null;
    }

    /**
     * $testCase の $connectionsToTransact プロパティの生の値を、正規化せずに返す。
     * プロパティが存在しない・未初期化の場合は空配列(=追加接続なし)を返す。
     * 値が配列でない場合は単一要素の配列として扱い、呼び出し元の型検査に委ねる。
     *
     * @return array<int, mixed>
     */
    private static function additionalConnectionValues(?object $testCase): array
    {
        if ($testCase === null) {
            return [];
        }

        try {
            $property = new ReflectionProperty($testCase, 'connectionsToTransact');
        } catch (ReflectionException) {
            return [];
        }

        // PHP 8.1以降、ReflectionPropertyはprivate/protectedでもsetAccessible()無しで
        // getValue()できる(8.5ではsetAccessible()自体が非推奨警告の対象になる)。
        if (! $property->isInitialized($testCase)) {
            return [];
        }

        $value = $property->getValue($testCase);

        return is_array($value) ? $value : [$value];
    }

    private static function rejected(string $connectionName, string $reason): RuntimeException
    {
        return new RuntimeException(
            "テスト用DB安全ガードにより停止しました。接続 [{$connectionName}] が許可された構成".
            "(sqliteドライバかつdatabase=:memory:)ではありません。理由: {$reason} ".
            'このテスト実行のDB接続設定(環境変数・.env・設定キャッシュ)を確認してください。'.
            '詳細は backend/tests/Support/TestDatabaseGuard.php を参照してください。'
        );
    }

    private static function rejectedInvalidConnectionName(mixed $value): RuntimeException
    {
        return new RuntimeException(
            'テスト用DB安全ガードにより停止しました。connectionsToTransact に不正な接続名'.
            '(空文字、または文字列以外の型 ['.get_debug_type($value).'])が指定されています。'.
            '詳細は backend/tests/Support/TestDatabaseGuard.php を参照してください。'
        );
    }
}
