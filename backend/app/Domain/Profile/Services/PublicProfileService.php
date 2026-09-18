<?php

namespace App\Domain\Profile\Services;

use App\Models\TrustScore;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PublicProfileService
{
    /**
     * 公開プロフィール閲覧のために信頼度スコアの存在確認と最大値の同期のみを
     * 補完する。ProfileService::ensureInitialized()と異なり、閲覧対象ユーザーの
     * Profile/ProfileVisibilityなど自己編集用データは一切作成・変更しない。
     * 一覧表示(summary)では都度この補完を呼ばないため、不要な書き込みは
     * 増えない。
     */
    public function ensureTrustScore(User $user): void
    {
        DB::transaction(function () use ($user) {
            TrustScore::ensureForUser($user);
        });
    }
}
