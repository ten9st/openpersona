<?php

namespace Tests\Feature;

use App\Domain\Profile\Services\ProfileService;
use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\TrustScore;
use App\Models\User;
use App\Models\UserCareer;
use App\Models\UserEducation;
use App\Services\TrustScoreService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FailingTrustScoreService;
use Tests\Support\TransactionSeamFailureForTest;
use Tests\Support\TrustScoreRecalculationFailedForTest;
use Tests\TestCase;

class ProfileServiceTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function createInitializedUser(): User
    {
        $user = User::factory()->create();

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

    private function bindFailingTrustScoreService(int $failOnCall): FailingTrustScoreService
    {
        $spy = new FailingTrustScoreService($failOnCall, new TrustScoreRecalculationFailedForTest(
            "trust score recalculation intentionally failed on call #{$failOnCall} for test"
        ));

        $this->app->instance(TrustScoreService::class, $spy);

        return $spy;
    }

    // ------------------------------------------------------------------
    // update(): 全項目のロールバック
    // ------------------------------------------------------------------

    public function test_update_rolls_back_all_written_data_when_final_recalculation_fails(): void
    {
        $user = User::factory()->create(['last_name' => '元姓']);

        Profile::create([
            'user_id' => $user->id,
            'region' => '東京都',
            'biography' => '旧紹介',
            'occupation' => '旧職',
        ]);

        foreach (array_keys(ProfileVisibility::defaultMap()) as $fieldName) {
            ProfileVisibility::create([
                'user_id' => $user->id,
                'field_name' => $fieldName,
                'is_public' => false,
            ]);
        }

        UserEducation::create([
            'user_id' => $user->id,
            'school_name' => '元の大学',
            'is_public' => true,
            'sort_order' => 0,
        ]);
        UserCareer::create([
            'user_id' => $user->id,
            'company_name' => '元の会社',
            'is_current' => false,
            'is_public' => true,
            'sort_order' => 0,
        ]);

        $baseline = (int) TrustScore::query()->where('user_id', $user->id)->value('total_score');

        // プロフィール項目(region/biography/occupation)を実際に変更するため、
        // Profile::updateOrCreate()自体もProfileObserver経由でcalculate()を
        // 1回発火させる(call #1、入れ替え前のeducations/careersに基づく計算)。
        // その後、学歴・職歴を入れ替えた最新状態でのProfileServiceの明示的な
        // 再計算(call #2)を失敗させる。
        $spy = $this->bindFailingTrustScoreService(failOnCall: 2);
        $service = app(ProfileService::class);

        $caught = null;

        try {
            $service->update($user, [
                'last_name' => '新姓',
                'first_name' => $user->first_name,
                'biography' => '新紹介',
                'occupation' => '新職',
                'region' => '大阪府',
                'visibilities' => [
                    'first_name' => true,
                    'biography' => true,
                    'occupation' => true,
                ],
                'educations' => [
                    ['school_name' => '新大学', 'is_public' => true],
                ],
                'careers' => [
                    ['company_name' => '新会社', 'is_current' => true, 'is_public' => true],
                ],
            ]);
        } catch (TrustScoreRecalculationFailedForTest $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '想定したタイミングでTrustScoreRecalculationFailedForTestが発生しませんでした。');

        // ProfileObserver由来の重複計算(call #1)と、ProfileServiceによる
        // 最終計算(call #2)の両方が実行されていることを確認する
        // (途中での計算重複の実測)。
        $this->assertSame(2, $spy->callCount());

        $user->refresh();
        $this->assertSame('元姓', $user->last_name, '氏名がロールバックされていません。');

        $profile = Profile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('東京都', $profile->region);
        $this->assertSame('旧紹介', $profile->biography);
        $this->assertSame('旧職', $profile->occupation);

        $this->assertFalse(
            (bool) ProfileVisibility::where('user_id', $user->id)->where('field_name', 'first_name')->value('is_public'),
            '公開設定がロールバックされていません。'
        );

        $this->assertSame(1, UserEducation::where('user_id', $user->id)->count());
        $this->assertSame('元の大学', UserEducation::where('user_id', $user->id)->value('school_name'));

        $this->assertSame(1, UserCareer::where('user_id', $user->id)->count());
        $this->assertSame('元の会社', UserCareer::where('user_id', $user->id)->value('company_name'));

        $this->assertSame(
            $baseline,
            (int) TrustScore::query()->where('user_id', $user->id)->value('total_score'),
            '信頼度スコアがロールバックされていません。'
        );
    }

    // ------------------------------------------------------------------
    // update(): 信頼度スコアのロールバック(実際に値が変わる条件)
    // ------------------------------------------------------------------

    public function test_update_rolls_back_trust_score_when_recalculation_fails_after_a_real_change(): void
    {
        $user = $this->createInitializedUser();

        $baseline = (int) TrustScore::query()->where('user_id', $user->id)->value('total_score');

        // region/biography/occupationは変更しない(ProfileObserverの重複発火を
        // 避け、計算呼び出しを1回だけに限定して失敗地点を厳密に特定する)。
        $spy = $this->bindFailingTrustScoreService(failOnCall: 1);
        $service = app(ProfileService::class);

        $caught = null;

        try {
            $service->update($user, [
                'last_name' => $user->last_name,
                'first_name' => $user->first_name,
                'biography' => null,
                'occupation' => null,
                'region' => '東京都',
                'visibilities' => ProfileVisibility::defaultMap(),
                // 学歴を追加すると実際にプロフィールスコアが加算される条件。
                'educations' => [
                    ['school_name' => '新大学', 'is_public' => true],
                ],
                'careers' => [],
            ]);
        } catch (TrustScoreRecalculationFailedForTest $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '想定したタイミングでTrustScoreRecalculationFailedForTestが発生しませんでした。');
        $this->assertSame(1, $spy->callCount());

        $this->assertNotSame(
            $baseline,
            $spy->observedTotalScores[0] ?? null,
            '学歴の追加によって信頼度スコアが実際に変化する条件になっていません(テストの前提が不成立)。'
        );

        $user->refresh();
        $this->assertSame(0, $user->educations()->count(), '学歴がロールバックされていません。');

        $this->assertSame(
            $baseline,
            (int) TrustScore::query()->where('user_id', $user->id)->value('total_score'),
            '信頼度スコアがロールバックされていません。'
        );
    }

    // ------------------------------------------------------------------
    // update(): 学歴・職歴の入れ替え途中の失敗
    // ------------------------------------------------------------------

    public function test_update_restores_original_educations_and_careers_when_a_later_career_write_fails(): void
    {
        $user = $this->createInitializedUser();

        UserEducation::create([
            'user_id' => $user->id,
            'school_name' => '元の大学',
            'is_public' => true,
            'sort_order' => 0,
        ]);
        UserCareer::create([
            'user_id' => $user->id,
            'company_name' => '元の会社',
            'is_current' => false,
            'is_public' => true,
            'sort_order' => 0,
        ]);

        $service = app(ProfileService::class);

        $caught = null;

        try {
            $service->update($user, [
                'last_name' => $user->last_name,
                'first_name' => $user->first_name,
                'biography' => null,
                'occupation' => null,
                'region' => '東京都',
                'visibilities' => ProfileVisibility::defaultMap(),
                // 学歴の入れ替え自体は正常に完了する。
                'educations' => [
                    ['school_name' => '新大学', 'is_public' => true],
                ],
                // 職歴は1件目が成功した直後、2件目でPDOバインドエラーを起こす
                // (positionに配列を渡すと「Array to string conversion」で失敗)。
                'careers' => [
                    ['company_name' => '新会社1', 'is_current' => false, 'is_public' => true],
                    ['company_name' => '新会社2', 'position' => ['invalid'], 'is_current' => true, 'is_public' => true],
                ],
            ]);
        } catch (QueryException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '職歴の書き込み失敗時にQueryExceptionが発生するはずです。');
        $this->assertStringContainsString(
            'user_careers',
            $caught->getSql(),
            '想定と異なる箇所で例外が発生しています(user_careersへの書き込みではありません)。'
        );

        $user->refresh();

        // 先に入れ替えが完了していた学歴も、後続の職歴書き込み失敗によって
        // ロールバックされ、元のデータに戻っていることを確認する。
        $this->assertSame(1, $user->educations()->count(), '学歴の件数が元の状態に復元されていません。');
        $this->assertSame('元の大学', $user->educations()->first()->school_name);

        $this->assertSame(1, $user->careers()->count(), '職歴の件数が元の状態に復元されていません。');
        $this->assertSame('元の会社', $user->careers()->first()->company_name);
    }

    // ------------------------------------------------------------------
    // ensureInitialized(): べき等性
    // ------------------------------------------------------------------

    public function test_ensure_initialized_is_idempotent_and_does_not_overwrite_existing_settings(): void
    {
        $user = User::factory()->create();
        $service = app(ProfileService::class);

        $service->ensureInitialized($user);

        // 既定値から変更しておく。
        ProfileVisibility::where('user_id', $user->id)
            ->where('field_name', 'biography')
            ->update(['is_public' => true]);

        TrustScore::where('user_id', $user->id)->update([
            'profile_score' => 42,
            'total_score' => 42,
        ]);

        $service->ensureInitialized($user);
        $service->ensureInitialized($user);

        $this->assertSame(1, Profile::where('user_id', $user->id)->count(), 'Profileが重複作成されています。');
        $this->assertSame(3, ProfileVisibility::where('user_id', $user->id)->count(), 'ProfileVisibilityが重複作成されています。');
        $this->assertTrue(
            (bool) ProfileVisibility::where('user_id', $user->id)->where('field_name', 'biography')->value('is_public'),
            '既存の公開設定が初期データ補完で上書きされています。'
        );

        $this->assertSame(1, TrustScore::where('user_id', $user->id)->count(), 'TrustScoreが重複作成されています。');
        $this->assertSame(
            42,
            (int) TrustScore::where('user_id', $user->id)->value('total_score'),
            '既存のスコアが初期データ補完で上書きされています。'
        );
    }

    // ------------------------------------------------------------------
    // ensureInitialized(): 途中失敗時のロールバック
    // ------------------------------------------------------------------

    public function test_ensure_initialized_leaves_no_partial_completion_when_a_later_step_fails(): void
    {
        $user = User::factory()->create();

        // User::factory()->create()の時点でUserObserver経由のTrustScoreService::calculate()が
        // 既に実行され、TrustScoreは(このテストの対象であるensureInitialized()とは別に)
        // 既に1行作成されている。ensureInitialized()の失敗によってこれが変化しないことを
        // 確認するため、失敗させる前の状態を基準値として保持しておく。
        $trustScoreBefore = TrustScore::where('user_id', $user->id)->first()->only([
            'profile_score', 'posting_score', 'source_score', 'history_score', 'total_score', 'max_score',
        ]);

        // ProfileVisibility::defaultMap()は ['first_name', 'biography', 'occupation'] の順で
        // 処理されるため、'occupation'の作成時にのみ例外を発生させることで、
        // 'first_name'・'biography'は一時的に書き込まれた後に失敗させられる。
        ProfileVisibility::creating(function (ProfileVisibility $visibility) {
            if ($visibility->field_name === 'occupation') {
                throw new TransactionSeamFailureForTest(
                    'occupation visibility creation intentionally failed for test'
                );
            }
        });

        $service = app(ProfileService::class);

        $caught = null;

        try {
            $service->ensureInitialized($user);
        } catch (TransactionSeamFailureForTest $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '想定したタイミングでTransactionSeamFailureForTestが発生しませんでした。');

        $this->assertSame(
            0,
            Profile::where('user_id', $user->id)->count(),
            'Profileの補完がロールバックされずに残ってしまっています。'
        );
        $this->assertSame(
            0,
            ProfileVisibility::where('user_id', $user->id)->count(),
            '公開設定の補完が部分的に残ってしまっています(occupationより前のfirst_name/biographyがロールバックされていません)。'
        );

        // TrustScoreの行自体はensureInitialized()より前(ユーザー作成時)から
        // 存在するため件数は1のままだが、その内容がensureInitialized()の
        // 失敗によって変化していないことを確認する。
        $this->assertSame(
            1,
            TrustScore::where('user_id', $user->id)->count(),
            'ユーザー作成時に既に存在していたTrustScoreの行数が変化しています。'
        );
        $this->assertSame(
            $trustScoreBefore,
            TrustScore::where('user_id', $user->id)->first()->only([
                'profile_score', 'posting_score', 'source_score', 'history_score', 'total_score', 'max_score',
            ]),
            'ensureInitialized()の失敗によってTrustScoreの内容が変化してしまっています。'
        );
    }
}
