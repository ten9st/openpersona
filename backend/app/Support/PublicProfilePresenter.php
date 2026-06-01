<?php

namespace App\Support;

use App\Models\ProfileVisibility;
use App\Models\User;
use App\Models\UserCareer;
use App\Models\UserEducation;

class PublicProfilePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(User $user): array
    {
        self::loadSummaryRelations($user);

        return [
            'id' => $user->id,
            'last_name' => $user->last_name,
            'first_name' => self::isFieldPublic($user, 'first_name')
                ? $user->first_name
                : null,
            'age' => $user->birthdate?->age,
            'region' => $user->profile?->region,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(User $user): array
    {
        self::loadDetailRelations($user);

        $biography = self::isFieldPublic($user, 'biography')
            ? $user->profile?->biography
            : null;

        $occupation = self::isFieldPublic($user, 'occupation')
            ? $user->profile?->occupation
            : null;

        return [
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
    }

    private static function loadSummaryRelations(User $user): void
    {
        $user->loadMissing([
            'profile:id,user_id,region',
            'profileVisibilities' => fn ($query) => $query
                ->select(['id', 'user_id', 'field_name', 'is_public'])
                ->where('field_name', 'first_name'),
        ]);
    }

    private static function loadDetailRelations(User $user): void
    {
        $user->loadMissing([
            'profile:id,user_id,biography,occupation,region',
            'profileVisibilities:id,user_id,field_name,is_public',
            'educations' => fn ($query) => $query
                ->where('is_public', true)
                ->orderBy('sort_order'),
            'careers' => fn ($query) => $query
                ->where('is_public', true)
                ->orderBy('sort_order'),
        ]);
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
