<?php

namespace App\Support;

use App\Models\IdentityVerification;
use App\Models\ProfileVisibility;
use App\Models\TrustScore;
use App\Models\User;
use App\Models\UserCareer;
use App\Models\UserEducation;

class PublicProfilePresenter
{
    /**
     * 呼び出し元は事前に必要な関連(profile/profileVisibilities/
     * identityVerifications/trustScore。PublicProfileQueryService::
     * summaryRelations()を参照)を読み込み済みのUserを渡す。
     * DBへの問い合わせ・書き込みは行わない。
     *
     * @return array<string, mixed>
     */
    public static function summary(User $user): array
    {
        return [
            'id' => $user->id,
            'last_name' => $user->last_name,
            'first_name' => self::isFieldPublic($user, 'first_name')
                ? $user->first_name
                : null,
            'age' => $user->birthdate?->age,
            'region' => $user->profile?->region,
            'trust_score' => self::trustScoreArray($user),
            'identity_verified' => self::isIdentityVerified($user),
        ];
    }

    /**
     * 呼び出し元は事前にPublicProfileQueryService::forDetail()で取得した
     * User(profile/profileVisibilities/identityVerifications/educations/
     * careers/trustScore/followers_count/following_countを読み込み済み)を渡す。
     * DBへの問い合わせ・書き込みは行わない。
     *
     * @return array<string, mixed>
     */
    public static function detail(User $user, ?bool $isFollowing = null): array
    {
        $biography = self::isFieldPublic($user, 'biography')
            ? $user->profile?->biography
            : null;

        $occupation = self::isFieldPublic($user, 'occupation')
            ? $user->profile?->occupation
            : null;

        $payload = [
            'user' => [
                'id' => $user->id,
                'last_name' => $user->last_name,
                'first_name' => self::isFieldPublic($user, 'first_name')
                    ? $user->first_name
                    : null,
                'age' => $user->birthdate?->age,
                'region' => $user->profile?->region,
            ],
            'profile' => [
                'biography' => $biography,
                'occupation' => $occupation,
            ],
            'trust_score' => self::trustScoreArray($user),
            'identity_verified' => self::isIdentityVerified($user),
            'followers_count' => (int) ($user->followers_count ?? 0),
            'following_count' => (int) ($user->following_count ?? 0),
            'educations' => $user->educations->map(fn (UserEducation $e) => [
                'school_name' => $e->school_name,
                'faculty' => $e->faculty,
                'degree' => $e->degree,
                'start_year' => $e->start_year,
                'end_year' => $e->end_year,
            ])->values(),
            'careers' => $user->careers->map(fn (UserCareer $c) => [
                'company_name' => $c->company_name,
                'position' => $c->position,
                'start_year' => $c->start_year,
                'end_year' => $c->end_year,
                'is_current' => $c->is_current,
            ])->values(),
        ];

        if ($isFollowing !== null) {
            $payload['is_following'] = $isFollowing;
        }

        return $payload;
    }

    /**
     * max_scoreは本人確認状態(identityVerifications)から都度導出する。
     * TrustScoreの行が存在しない場合や、本人確認状態の変更(確認済み化・
     * 取り消しのいずれも)がまだDBへ同期されていない場合でも、表示上は
     * 常に整合した値になる。total_scoreはDBの値をそのまま使用し、
     * ここでは書き換え・ゼロ戻しは行わない(実際のDB同期はPublicProfileService
     * ::ensureTrustScore()の責務)。
     */
    private static function trustScoreArray(User $user): array
    {
        $maxScore = self::isIdentityVerified($user)
            ? TrustScore::MAX_SCORE_VERIFIED
            : TrustScore::MAX_SCORE_UNVERIFIED;

        return [
            'total_score' => (int) ($user->trustScore->total_score ?? 0),
            'max_score' => $maxScore,
        ];
    }

    /**
     * User::isIdentityVerified()は呼び出すたびにクエリを発行するため、
     * ここでは事前に読み込み済みのidentityVerificationsコレクションから
     * メモリ上で判定する(整形処理中にDB問い合わせを発生させないため)。
     */
    private static function isIdentityVerified(User $user): bool
    {
        return $user->identityVerifications->contains(
            fn (IdentityVerification $verification) => $verification->verification_status === IdentityVerification::STATUS_VERIFIED
        );
    }

    private static function isFieldPublic(User $user, string $field): bool
    {
        $visibility = $user->profileVisibilities
            ->firstWhere('field_name', $field);

        if ($visibility !== null) {
            return (bool) $visibility->is_public;
        }

        return ProfileVisibility::defaultMap()[$field] ?? false;
    }
}
