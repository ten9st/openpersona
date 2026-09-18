<?php

namespace App\Domain\Profile\Services;

use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\TrustScore;
use App\Models\User;
use App\Services\TrustScoreService;
use Illuminate\Support\Facades\DB;

class ProfileService
{
    public function __construct(
        private TrustScoreService $trustScoreService,
    ) {}

    /**
     * 本人向けプロフィール表示に必要な初期データ(プロフィール・公開設定・
     * 信頼度スコア)を補完する。既に存在するデータは変更しないべき等な処理。
     */
    public function ensureInitialized(User $user): void
    {
        DB::transaction(function () use ($user) {
            Profile::firstOrCreate(['user_id' => $user->id]);

            foreach (ProfileVisibility::defaultMap() as $fieldName => $isPublic) {
                ProfileVisibility::firstOrCreate(
                    ['user_id' => $user->id, 'field_name' => $fieldName],
                    ['is_public' => $isPublic]
                );
            }

            ProfileVisibility::where('user_id', $user->id)
                ->whereNotIn('field_name', ProfileVisibility::FIELDS)
                ->delete();

            TrustScore::ensureForUser($user);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(User $user, array $validated): void
    {
        DB::transaction(function () use ($user, $validated) {
            if (! $user->hasLockedBasicInfo()) {
                $user->update([
                    'last_name' => $validated['last_name'],
                    'first_name' => $validated['first_name'],
                ]);
            }

            Profile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'biography' => $validated['biography'] ?? null,
                    'occupation' => $validated['occupation'] ?? null,
                    'region' => $validated['region'] ?? null,
                ]
            );

            foreach ($validated['visibilities'] as $fieldName => $isPublic) {
                if (! in_array($fieldName, ProfileVisibility::FIELDS, true)) {
                    continue;
                }

                ProfileVisibility::updateOrCreate(
                    ['user_id' => $user->id, 'field_name' => $fieldName],
                    ['is_public' => $isPublic]
                );
            }

            $this->syncEducations($user, $validated['educations']);
            $this->syncCareers($user, $validated['careers']);

            // 保存直後の最新状態で再計算する。profile/educations/careers は
            // (UserObserver::created()経由のTrustScoreService::calculate()などで)
            // 既にloadMissing()済みキャッシュが残っている場合があり、loadMissing()
            // では更新前の値のまま計算されてしまうため、ここで強制的に再読み込みする。
            $user->load(['profile', 'educations', 'careers']);

            $this->trustScoreService->calculate($user);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $educations
     */
    private function syncEducations(User $user, array $educations): void
    {
        $user->educations()->delete();

        foreach ($educations as $index => $education) {
            $user->educations()->create([
                'school_name' => $education['school_name'],
                'faculty' => $education['faculty'] ?? null,
                'degree' => $education['degree'] ?? null,
                'start_year' => $education['start_year'] ?? null,
                'end_year' => $education['end_year'] ?? null,
                'is_public' => $education['is_public'],
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $careers
     */
    private function syncCareers(User $user, array $careers): void
    {
        $user->careers()->delete();

        $currentIndex = null;
        foreach ($careers as $index => $career) {
            if (! empty($career['is_current']) && $currentIndex === null) {
                $currentIndex = $index;
            }
        }

        foreach ($careers as $index => $career) {
            $user->careers()->create([
                'company_name' => $career['company_name'],
                'position' => $career['position'] ?? null,
                'start_year' => $career['start_year'] ?? null,
                'end_year' => ($currentIndex === $index) ? null : ($career['end_year'] ?? null),
                'is_current' => $currentIndex === $index,
                'is_public' => $career['is_public'],
                'sort_order' => $index,
            ]);
        }
    }
}
