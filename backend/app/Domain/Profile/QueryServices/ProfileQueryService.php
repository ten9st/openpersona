<?php

namespace App\Domain\Profile\QueryServices;

use App\Models\ProfileVisibility;
use App\Models\User;

class ProfileQueryService
{
    /**
     * 本人向けプロフィール表示に必要な関連データをまとめて取得する。
     * 書き込みは行わない(初期データの補完はProfileServiceの責務)。
     *
     * 呼び出し元の$userは、直前のProfileServiceでの更新や、同一オブジェクトが
     * 使い回される経路(テストのSanctum::actingAs()など)によって、profile/
     * educations/careers/trustScoreが更新前の値でキャッシュされている場合が
     * ある。loadMissing()では既にロード済みの古い値がそのまま使われてしまう
     * ため、load()で必ず最新の状態に取得し直す。
     */
    public function forUser(User $user): User
    {
        return $user->load([
            'profile',
            'profileVisibilities' => fn ($query) => $query
                ->whereIn('field_name', ProfileVisibility::FIELDS),
            'educations',
            'careers',
            'trustScore',
        ]);
    }
}
