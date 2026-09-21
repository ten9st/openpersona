<?php

namespace Tests\Feature;

use App\Domain\Auth\Services\AuthService;
use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\TrustScore;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\TrustScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FailingTrustScoreService;
use Tests\Support\TransactionSeamFailureForTest;
use Tests\Support\TrustScoreRecalculationFailedForTest;
use Tests\TestCase;

/**
 * AuthService::register() が User〜公開設定の作成を1つのトランザクションで
 * 囲んでいることの回帰テスト。失敗地点ごとに、失敗時点までは先行データが
 * 実際に作成されていたことを確認したうえで、失敗後に今回分が一切残らないことを検証する。
 */
class AuthRegistrationTransactionTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'newcomer@example.com';

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        return [
            'email' => self::EMAIL,
            'password' => 'password123',
            'last_name' => '山田',
            'first_name' => '太郎',
            'birthdate' => '1990-01-01',
        ];
    }

    /**
     * 既存ユーザーと、その関連データ一式を作る。
     */
    private function createExistingUser(): User
    {
        $user = User::factory()->create(['email' => 'existing@example.com']);

        Profile::create(['user_id' => $user->id, 'region' => '東京都']);

        foreach (ProfileVisibility::defaultMap() as $fieldName => $isPublic) {
            ProfileVisibility::create([
                'user_id' => $user->id,
                'field_name' => $fieldName,
                'is_public' => $isPublic,
            ]);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'users' => User::query()->orderBy('id')->get()->toArray(),
            'profiles' => Profile::query()->orderBy('id')->get()->toArray(),
            'visibilities' => ProfileVisibility::query()->orderBy('id')->get()->toArray(),
            'trust_scores' => TrustScore::query()->orderBy('id')->get()->toArray(),
        ];
    }

    /**
     * @return array{users: int, trust_scores: int, profiles: int, visibilities: int}
     */
    private function counts(): array
    {
        return [
            'users' => User::count(),
            'trust_scores' => TrustScore::count(),
            'profiles' => Profile::count(),
            'visibilities' => ProfileVisibility::count(),
        ];
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function expectInjectedFailure(string $exceptionClass, callable $action): \Throwable
    {
        try {
            $action();
        } catch (\Throwable $e) {
            $this->assertInstanceOf(
                $exceptionClass,
                $e,
                '注入した例外ではない別の例外で失敗した: '.get_class($e).': '.$e->getMessage(),
            );

            return $e;
        }

        $this->fail('注入した失敗が発生せず、登録が最後まで成功してしまった。');
    }

    /**
     * UserObserverを、指定のTrustScoreServiceを使う状態で登録し直す。
     * Observerは起動時にインスタンス化されるため、コンテナへのbindだけでは
     * 差し替わらない。
     */
    private function reobserveUserWith(TrustScoreService $service): void
    {
        $this->app->instance(TrustScoreService::class, $service);

        User::flushEventListeners();
        User::observe(UserObserver::class);
    }

    private function assertNothingOfThisRegistrationRemains(array $before): void
    {
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    // ------------------------------------------------------------------
    // 1. UserObserver経由のTrustScore処理で失敗
    // ------------------------------------------------------------------

    public function test_rolls_back_when_trust_score_calculation_fails_in_user_observer(): void
    {
        $this->createExistingUser();
        $before = $this->snapshot();
        $beforeCounts = $this->counts();

        $injected = new TrustScoreRecalculationFailedForTest('trust score failure injected for register test');

        $spy = new class(1, $injected) extends FailingTrustScoreService
        {
            /** @var array<string, int> */
            public array $stateAtFailure = [];

            public function calculate(User $user): void
            {
                try {
                    parent::calculate($user);
                } catch (TrustScoreRecalculationFailedForTest $e) {
                    // 失敗の瞬間にDBへ書き込み済みだったデータを記録する
                    $this->stateAtFailure = [
                        'users' => User::where('email', 'newcomer@example.com')->count(),
                        'trust_scores' => TrustScore::where('user_id', $user->id)->count(),
                        'profiles' => Profile::where('user_id', $user->id)->count(),
                        'visibilities' => ProfileVisibility::where('user_id', $user->id)->count(),
                    ];

                    throw $e;
                }
            }
        };
        $this->reobserveUserWith($spy);

        $caught = $this->expectInjectedFailure(
            TrustScoreRecalculationFailedForTest::class,
            fn () => app(AuthService::class)->register($this->payload()),
        );

        $this->assertSame($injected, $caught);
        $this->assertSame(1, $spy->callCount());
        // 失敗地点(TrustScore計算直後)ではUserとTrustScoreが実際に作成済みだった
        $this->assertSame(
            ['users' => 1, 'trust_scores' => 1, 'profiles' => 0, 'visibilities' => 0],
            $spy->stateAtFailure,
        );

        $this->assertSame($beforeCounts, $this->counts());
        $this->assertNothingOfThisRegistrationRemains($before);
    }

    // ------------------------------------------------------------------
    // 2. Profile作成で失敗
    // ------------------------------------------------------------------

    public function test_rolls_back_when_profile_creation_fails(): void
    {
        $this->createExistingUser();
        $before = $this->snapshot();
        $beforeCounts = $this->counts();

        $armed = true;
        $calls = 0;
        $stateAtFailure = [];

        Profile::creating(function (Profile $profile) use (&$armed, &$calls, &$stateAtFailure) {
            if (! $armed) {
                return;
            }

            $calls++;
            $stateAtFailure = [
                'users' => User::where('email', self::EMAIL)->count(),
                'trust_scores' => TrustScore::where('user_id', $profile->user_id)->count(),
                'profiles' => Profile::where('user_id', $profile->user_id)->count(),
                'visibilities' => ProfileVisibility::where('user_id', $profile->user_id)->count(),
            ];

            throw new TransactionSeamFailureForTest('profile creation failure injected for register test');
        });

        $caught = $this->expectInjectedFailure(
            TransactionSeamFailureForTest::class,
            fn () => app(AuthService::class)->register($this->payload()),
        );

        $this->assertSame('profile creation failure injected for register test', $caught->getMessage());
        $this->assertSame(1, $calls);
        // 失敗地点(Profile作成直前)ではUserとObserver経由のTrustScoreが作成済みだった
        $this->assertSame(
            ['users' => 1, 'trust_scores' => 1, 'profiles' => 0, 'visibilities' => 0],
            $stateAtFailure,
        );

        $this->assertSame($beforeCounts, $this->counts());
        $this->assertNothingOfThisRegistrationRemains($before);

        // 部分的なUserが残っていないため、同じメールで再登録できる
        $armed = false;
        app(AuthService::class)->register($this->payload());
        $this->assertSame(2, User::count());
    }

    // ------------------------------------------------------------------
    // 3. 公開設定の途中で失敗
    // ------------------------------------------------------------------

    public function test_rolls_back_when_visibility_creation_fails_midway(): void
    {
        $this->createExistingUser();
        $before = $this->snapshot();
        $beforeCounts = $this->counts();

        $armed = true;
        $calls = 0;
        $stateAtFailure = [];

        ProfileVisibility::creating(function (ProfileVisibility $visibility) use (&$armed, &$calls, &$stateAtFailure) {
            if (! $armed) {
                return;
            }

            $userId = $visibility->user_id;
            if (User::where('id', $userId)->where('email', self::EMAIL)->doesntExist()) {
                return;
            }

            $calls++;

            // 2件目の公開設定を作る直前で失敗させる
            if ($calls === 2) {
                $stateAtFailure = [
                    'users' => User::where('email', self::EMAIL)->count(),
                    'trust_scores' => TrustScore::where('user_id', $userId)->count(),
                    'profiles' => Profile::where('user_id', $userId)->count(),
                    'visibilities' => ProfileVisibility::where('user_id', $userId)->count(),
                ];

                throw new TransactionSeamFailureForTest('visibility creation failure injected for register test');
            }
        });

        $caught = $this->expectInjectedFailure(
            TransactionSeamFailureForTest::class,
            fn () => app(AuthService::class)->register($this->payload()),
        );

        $this->assertSame('visibility creation failure injected for register test', $caught->getMessage());
        $this->assertSame(2, $calls);
        // 失敗地点ではUser・TrustScore・Profileと、1件目の公開設定が作成済みだった
        $this->assertSame(
            ['users' => 1, 'trust_scores' => 1, 'profiles' => 1, 'visibilities' => 1],
            $stateAtFailure,
        );

        $this->assertSame($beforeCounts, $this->counts());
        $this->assertNothingOfThisRegistrationRemains($before);

        $armed = false;
        app(AuthService::class)->register($this->payload());
        $this->assertSame(2, User::count());
        $this->assertSame(6, ProfileVisibility::count());
    }

    // ------------------------------------------------------------------
    // HTTP経由でも同様にロールバックされ、ユーザーが残らない
    // ------------------------------------------------------------------

    public function test_http_registration_rolls_back_when_profile_creation_fails(): void
    {
        $this->createExistingUser();
        $before = $this->snapshot();

        Profile::creating(function () {
            throw new TransactionSeamFailureForTest('profile creation failure injected for http register test');
        });

        $this->withoutExceptionHandling();

        $this->expectInjectedFailure(
            TransactionSeamFailureForTest::class,
            fn () => $this->postJson('/api/register', $this->payload()),
        );

        $this->assertNothingOfThisRegistrationRemains($before);
    }

    // ------------------------------------------------------------------
    // 成功時: TrustScore計算はUserObserverの1回だけ(二重実行しない)
    // ------------------------------------------------------------------

    public function test_trust_score_is_calculated_exactly_once_on_success(): void
    {
        $spy = new FailingTrustScoreService(
            PHP_INT_MAX,
            new TrustScoreRecalculationFailedForTest('never thrown'),
        );
        $this->reobserveUserWith($spy);

        $user = app(AuthService::class)->register($this->payload());

        $this->assertSame(1, $spy->callCount());
        $this->assertSame(1, TrustScore::where('user_id', $user->id)->count());
        $this->assertSame(1, Profile::where('user_id', $user->id)->count());
        $this->assertSame(3, ProfileVisibility::where('user_id', $user->id)->count());
    }
}
