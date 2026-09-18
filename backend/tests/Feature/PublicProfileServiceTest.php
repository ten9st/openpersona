<?php

namespace Tests\Feature;

use App\Domain\Profile\QueryServices\PublicProfileQueryService;
use App\Domain\Profile\Services\PublicProfileService;
use App\Models\IdentityVerification;
use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\TrustScore;
use App\Models\User;
use App\Models\UserCareer;
use App\Models\UserEducation;
use App\Support\PublicProfilePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TransactionSeamFailureForTest;
use Tests\TestCase;

class PublicProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // ensureTrustScore(): べき等性
    // ------------------------------------------------------------------

    public function test_ensure_trust_score_is_idempotent_and_does_not_overwrite_existing_score(): void
    {
        $user = User::factory()->create();
        $service = app(PublicProfileService::class);

        $service->ensureTrustScore($user);

        TrustScore::where('user_id', $user->id)->update([
            'profile_score' => 42,
            'total_score' => 42,
        ]);

        $service->ensureTrustScore($user);
        $service->ensureTrustScore($user);

        $this->assertSame(1, TrustScore::where('user_id', $user->id)->count(), 'TrustScoreが重複作成されています。');
        $this->assertSame(
            42,
            (int) TrustScore::where('user_id', $user->id)->value('total_score'),
            '既存のスコアが初期データ補完で上書きされています。'
        );
    }

    // ------------------------------------------------------------------
    // ensureTrustScore(): 途中失敗時のロールバック
    // ------------------------------------------------------------------

    public function test_ensure_trust_score_rolls_back_when_max_score_sync_fails(): void
    {
        $user = User::factory()->create();

        IdentityVerification::create([
            'user_id' => $user->id,
            'verification_method' => 'driver_license',
            'verification_status' => IdentityVerification::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        // User::factory()->create()時点のTrustScore(UserObserver経由)を削除し、
        // ensureTrustScore()が「新規作成(max_score=未確認のデフォルト)→
        // 本人確認済みのため即座に補正更新」という2段階の書き込みを行う
        // 状況を作る。2段階目のupdate時にのみ例外を発生させることで、
        // 1段階目の作成が既にコミットされた状態からのロールバックを検証する。
        TrustScore::where('user_id', $user->id)->delete();

        TrustScore::updating(function (TrustScore $trustScore) {
            throw new TransactionSeamFailureForTest(
                'max_score sync update intentionally failed for test'
            );
        });

        $service = app(PublicProfileService::class);

        $caught = null;

        try {
            $service->ensureTrustScore($user);
        } catch (TransactionSeamFailureForTest $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '想定したタイミングでTransactionSeamFailureForTestが発生しませんでした。');

        $this->assertSame(
            0,
            TrustScore::where('user_id', $user->id)->count(),
            '新規作成されたTrustScoreがロールバックされずに残ってしまっています。'
        );
    }

    // ------------------------------------------------------------------
    // summary(): 本人確認状態とmax_scoreの整合性
    // ------------------------------------------------------------------

    public function test_summary_shows_verified_max_score_when_trust_score_max_score_is_stale(): void
    {
        $user = User::factory()->create();

        IdentityVerification::create([
            'user_id' => $user->id,
            'verification_method' => 'driver_license',
            'verification_status' => IdentityVerification::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        // User::factory()->create()時点(本人確認前)のTrustScoreが、
        // max_score=未確認のまま同期されずに残っている状況を再現する。
        TrustScore::where('user_id', $user->id)->update([
            'max_score' => TrustScore::MAX_SCORE_UNVERIFIED,
            'total_score' => 15,
        ]);

        $user->load(PublicProfileQueryService::summaryRelations());

        $summary = PublicProfilePresenter::summary($user);

        $this->assertTrue($summary['identity_verified']);
        $this->assertSame(TrustScore::MAX_SCORE_VERIFIED, $summary['trust_score']['max_score']);
        $this->assertSame(15, $summary['trust_score']['total_score'], '既存のtotal_scoreが変化してしまっています。');
    }

    public function test_summary_shows_verified_max_score_when_trust_score_is_missing(): void
    {
        $user = User::factory()->create();

        IdentityVerification::create([
            'user_id' => $user->id,
            'verification_method' => 'driver_license',
            'verification_status' => IdentityVerification::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        // TrustScoreの行自体が存在しない状況を再現する。
        TrustScore::where('user_id', $user->id)->delete();

        $user->load(PublicProfileQueryService::summaryRelations());

        $summary = PublicProfilePresenter::summary($user);

        $this->assertTrue($summary['identity_verified']);
        $this->assertSame(TrustScore::MAX_SCORE_VERIFIED, $summary['trust_score']['max_score']);
        $this->assertSame(0, $summary['trust_score']['total_score']);
    }

    public function test_summary_shows_unverified_max_score_when_identity_verification_is_no_longer_verified(): void
    {
        $user = User::factory()->create();

        // 本人確認済みだった時点のmax_score=100が、確認取り消し後も
        // 同期されずに残っている状況を再現する(IdentityVerificationは
        // 作成しない = 現在は未確認)。
        TrustScore::where('user_id', $user->id)->update([
            'max_score' => TrustScore::MAX_SCORE_VERIFIED,
            'total_score' => 20,
        ]);

        $user->load(PublicProfileQueryService::summaryRelations());

        $summary = PublicProfilePresenter::summary($user);

        $this->assertFalse($summary['identity_verified']);
        $this->assertSame(TrustScore::MAX_SCORE_UNVERIFIED, $summary['trust_score']['max_score']);
        $this->assertSame(20, $summary['trust_score']['total_score'], '既存のtotal_scoreが変化してしまっています。');
    }

    // ------------------------------------------------------------------
    // Presenter::summary()の整形処理中にDB問い合わせ・書き込みが発生しないこと
    // ------------------------------------------------------------------

    public function test_summary_presenter_does_not_query_the_database(): void
    {
        $user = User::factory()->create();
        Profile::create(['user_id' => $user->id, 'region' => '東京都']);

        $user->load(PublicProfileQueryService::summaryRelations());

        DB::enableQueryLog();
        DB::flushQueryLog();

        PublicProfilePresenter::summary($user);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            [],
            $queries,
            'Presenter::summary()の整形処理中にDBクエリが発生しています: '.json_encode($queries)
        );
    }

    // ------------------------------------------------------------------
    // forDetail(): 事前ロード済みリレーションからの非公開情報漏えい防止
    // ------------------------------------------------------------------

    public function test_for_detail_overrides_unfiltered_preloaded_educations_and_careers(): void
    {
        $user = User::factory()->create();
        Profile::create(['user_id' => $user->id, 'region' => '東京都']);

        UserEducation::create([
            'user_id' => $user->id, 'school_name' => '公開大学', 'is_public' => true, 'sort_order' => 0,
        ]);
        UserEducation::create([
            'user_id' => $user->id, 'school_name' => '非公開大学', 'is_public' => false, 'sort_order' => 1,
        ]);
        UserCareer::create([
            'user_id' => $user->id, 'company_name' => '公開会社', 'is_public' => true, 'sort_order' => 0,
        ]);
        UserCareer::create([
            'user_id' => $user->id, 'company_name' => '非公開会社', 'is_public' => false, 'sort_order' => 1,
        ]);

        // 何らかの別経路で、公開絞り込みなしの学歴・職歴が事前ロードされて
        // しまっているケースを想定する。
        $user->load(['educations', 'careers']);
        $this->assertCount(2, $user->educations, '事前条件: 絞り込みなしで2件読み込まれているはず。');
        $this->assertCount(2, $user->careers, '事前条件: 絞り込みなしで2件読み込まれているはず。');

        $queryService = app(PublicProfileQueryService::class);
        $data = $queryService->forDetail($user, null);

        $this->assertCount(1, $data['user']->educations, '非公開の学歴が公開詳細に含まれてしまっています。');
        $this->assertSame('公開大学', $data['user']->educations->first()->school_name);

        $this->assertCount(1, $data['user']->careers, '非公開の職歴が公開詳細に含まれてしまっています。');
        $this->assertSame('公開会社', $data['user']->careers->first()->company_name);
    }

    // ------------------------------------------------------------------
    // summary() → forDetail()/detail() を同一Userインスタンスで順に処理
    // ------------------------------------------------------------------

    public function test_summary_then_detail_on_same_user_instance_is_correct(): void
    {
        $user = User::factory()->create(['first_name' => '太郎']);
        Profile::create(['user_id' => $user->id, 'region' => '東京都', 'biography' => '自己紹介']);

        foreach (['first_name' => true, 'biography' => true, 'occupation' => false] as $field => $isPublic) {
            ProfileVisibility::create([
                'user_id' => $user->id,
                'field_name' => $field,
                'is_public' => $isPublic,
            ]);
        }

        UserEducation::create([
            'user_id' => $user->id, 'school_name' => '公開大学', 'is_public' => true, 'sort_order' => 0,
        ]);
        UserEducation::create([
            'user_id' => $user->id, 'school_name' => '非公開大学', 'is_public' => false, 'sort_order' => 1,
        ]);

        // summary()を先に呼んでも、後続のdetail()向けデータ取得が
        // 正しく動作することを確認する(同一Userインスタンスを使い回す)。
        // summary()は事前準備済みのデータを前提とするため、呼び出し元に
        // 相当する準備をここで行う。
        $user->load(PublicProfileQueryService::summaryRelations());
        $summary = PublicProfilePresenter::summary($user);
        $this->assertSame('太郎', $summary['first_name']);

        $queryService = app(PublicProfileQueryService::class);
        $data = $queryService->forDetail($user, null);
        $detail = PublicProfilePresenter::detail($data['user'], $data['is_following']);

        $this->assertSame('太郎', $detail['user']['first_name']);
        $this->assertSame('自己紹介', $detail['profile']['biography']);
        $this->assertCount(1, $detail['educations'], 'summary()の後にdetail()を呼ぶと学歴の絞り込みが崩れています。');
        $this->assertSame('公開大学', $detail['educations'][0]['school_name']);
        $this->assertArrayNotHasKey('is_following', $detail, 'ゲスト時はis_followingキーを含まないはず。');
    }

    // ------------------------------------------------------------------
    // Presenter::detail()の整形処理中にDB問い合わせ・書き込みが発生しないこと
    // ------------------------------------------------------------------

    public function test_detail_presenter_does_not_query_the_database(): void
    {
        $user = User::factory()->create();
        Profile::create(['user_id' => $user->id, 'region' => '東京都']);

        app(PublicProfileService::class)->ensureTrustScore($user);
        $data = app(PublicProfileQueryService::class)->forDetail($user, null);

        DB::enableQueryLog();
        DB::flushQueryLog();

        PublicProfilePresenter::detail($data['user'], $data['is_following']);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            [],
            $queries,
            'Presenter::detail()の整形処理中にDBクエリが発生しています: '.json_encode($queries)
        );
    }
}
