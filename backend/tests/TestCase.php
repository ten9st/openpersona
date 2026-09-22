<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Illuminate\Foundation\Testing\TestCase::createApplication() をベースに、
     * TestDatabaseGuard::assertSafe() の登録を1行追加しただけの実装。
     * (laravel/framework ^13.7 時点のソースに合わせている。既存の動作
     * (WithCachedConfig/WithCachedRoutesの反映、Kernelのbootstrap)は変更していない。)
     *
     * Illuminate\Foundation\Bootstrap\LoadConfiguration の直後
     * (Illuminate\Foundation\Bootstrap\RegisterProviders / BootProviders より前)に
     * ガードを実行するには、$app->make(Kernel::class)->bootstrap() が呼ばれる前に
     * $app->afterBootstrapping(LoadConfiguration::class, ...) でリスナーを登録しておく
     * 必要がある(Illuminate\Foundation\Application::bootstrapWith() は各bootstrapperの
     * 実行直後に 'bootstrapped: <bootstrapper>' イベントを同期的にdispatchするため)。
     * RefreshDatabase::beforeRefreshingDatabase() 等のトレイト側フックは、各テストクラスが
     * use RefreshDatabase する際にこのクラスの継承より優先されて上書きされてしまうため使えない
     * (トレイトのメソッドは、それを使うクラス自身に直接定義されたものとして扱われ、
     * 継承元クラスのメソッドより優先される)。
     *
     * ガードはDBに一切接続しない。判定ロジックの単体テストは
     * tests/Unit/Support/TestDatabaseGuardTest.php を参照。
     */
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }

        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn () => $this->markRoutesCached($app));
        }

        $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
            TestDatabaseGuard::assertSafe($app, $this);
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
