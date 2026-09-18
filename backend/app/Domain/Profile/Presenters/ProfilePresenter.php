<?php

namespace App\Domain\Profile\Presenters;

use App\Models\ProfileVisibility;
use App\Models\User;
use App\Models\UserCareer;
use App\Models\UserEducation;

class ProfilePresenter
{
    /**
     * 取得済みのUser(profile/profileVisibilities/educations/careers/trustScore
     * を読み込み済み)から、本人向けプロフィールAPIのレスポンスを組み立てる。
     * DBへの保存や初期データ補完は行わない。
     *
     * @return array<string, mixed>
     */
    public static function format(User $user): array
    {
        return [
            'meta' => [
                'basic_info_locked' => $user->basicInfoLockedFields(),
                'identity_verified' => $user->isIdentityVerified(),
            ],
            'user' => [
                'email' => $user->email,
                'last_name' => $user->last_name,
                'first_name' => $user->first_name,
                'birthdate' => $user->birthdate?->format('Y-m-d'),
            ],
            'profile' => [
                'biography' => $user->profile?->biography,
                'occupation' => $user->profile?->occupation,
                'region' => $user->profile?->region,
            ],
            'trust_score' => $user->trustScore->toPublicArray(),
            'visibilities' => self::visibilities($user),
            'educations' => $user->educations->map(fn (UserEducation $e) => [
                'id' => $e->id,
                'school_name' => $e->school_name,
                'faculty' => $e->faculty,
                'degree' => $e->degree,
                'start_year' => $e->start_year,
                'end_year' => $e->end_year,
                'is_public' => $e->is_public,
                'sort_order' => $e->sort_order,
            ])->values(),
            'careers' => $user->careers->map(fn (UserCareer $c) => [
                'id' => $c->id,
                'company_name' => $c->company_name,
                'position' => $c->position,
                'start_year' => $c->start_year,
                'end_year' => $c->end_year,
                'is_current' => $c->is_current,
                'is_public' => $c->is_public,
                'sort_order' => $c->sort_order,
            ])->values(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private static function visibilities(User $user): array
    {
        $map = ProfileVisibility::defaultMap();

        foreach ($user->profileVisibilities as $visibility) {
            if (array_key_exists($visibility->field_name, $map)) {
                $map[$visibility->field_name] = (bool) $visibility->is_public;
            }
        }

        return $map;
    }
}
