<?php

namespace Tests\Support;

use Illuminate\Support\ServiceProvider;

/**
 * DatabaseGuardBootstrapIntegrationTest専用の、呼び出し記録だけを行うダミーProvider。
 *
 * DBにも他の外部サービスにも一切触れない。register()/boot()が実際に呼ばれたかどうかを、
 * 静的な配列に記録するだけ。テスト用の最小Laravelアプリの bootstrap/providers.php から
 * このクラスのFQCNを直接参照する(Composerのautoloadで解決されるため、テスト側で
 * 個別にrequireする必要はない)。
 */
class RecordingServiceProvider extends ServiceProvider
{
    /** @var array<int, string> */
    public static array $log = [];

    public static function reset(): void
    {
        self::$log = [];
    }

    public function register(): void
    {
        self::$log[] = 'register';
    }

    public function boot(): void
    {
        self::$log[] = 'boot';
    }
}
