<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\User;
use App\Models\UserCareer;
use App\Models\UserEducation;
use App\Support\UserBasicInfoRules;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $profile = $this->findOrCreateProfile($user);

        return response()->json($this->buildProfilePayload($user, $profile));
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $request->merge(UserBasicInfoRules::trimInput($request->all()));

        $visibilityRules = [];
        foreach (ProfileVisibility::FIELDS as $field) {
            $visibilityRules["visibilities.{$field}"] = ['required', 'boolean'];
        }

        $validated = $request->validate([
            ...UserBasicInfoRules::profileRules(),
            'biography' => ['nullable', 'string'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'visibilities' => ['required', 'array'],
            'educations' => ['present', 'array'],
            'educations.*.school_name' => ['required', 'string', 'max:255'],
            'educations.*.faculty' => ['nullable', 'string', 'max:255'],
            'educations.*.degree' => ['nullable', 'string', 'max:255'],
            'educations.*.start_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'educations.*.end_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'educations.*.is_public' => ['required', 'boolean'],
            'careers' => ['present', 'array'],
            'careers.*.company_name' => ['required', 'string', 'max:255'],
            'careers.*.position' => ['nullable', 'string', 'max:255'],
            'careers.*.start_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'careers.*.end_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'careers.*.is_current' => ['required', 'boolean'],
            'careers.*.is_public' => ['required', 'boolean'],
            ...$visibilityRules,
        ], UserBasicInfoRules::messages());

        $user->update([
            'last_name' => $validated['last_name'],
            'first_name' => $validated['first_name'],
            'birthdate' => $validated['birthdate'],
        ]);

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
                [
                    'user_id' => $user->id,
                    'field_name' => $fieldName,
                ],
                ['is_public' => $isPublic]
            );
        }

        $this->syncEducations($user, $validated['educations']);
        $this->syncCareers($user, $validated['careers']);

        $profile = Profile::where('user_id', $user->id)->firstOrFail();

        return response()->json([
            'message' => 'プロフィールを更新しました。',
            ...$this->buildProfilePayload($user->fresh(), $profile),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProfilePayload(User $user, Profile $profile): array
    {
        return [
            'user' => [
                'last_name' => $user->last_name,
                'first_name' => $user->first_name,
                'birthdate' => $user->birthdate?->format('Y-m-d'),
            ],
            'profile' => [
                'biography' => $profile->biography,
                'occupation' => $profile->occupation,
                'region' => $profile->region,
            ],
            'visibilities' => $this->getVisibilities($user),
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

    private function findOrCreateProfile(User $user): Profile
    {
        $profile = Profile::firstOrCreate(['user_id' => $user->id]);
        $this->ensureVisibilities($user);

        return $profile;
    }

    private function ensureVisibilities(User $user): void
    {
        foreach (ProfileVisibility::defaultMap() as $fieldName => $isPublic) {
            ProfileVisibility::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'field_name' => $fieldName,
                ],
                ['is_public' => $isPublic]
            );
        }

        ProfileVisibility::where('user_id', $user->id)
            ->whereNotIn('field_name', ProfileVisibility::FIELDS)
            ->delete();
    }

    /**
     * @return array<string, bool>
     */
    private function getVisibilities(User $user): array
    {
        $this->ensureVisibilities($user);

        $rows = ProfileVisibility::where('user_id', $user->id)
            ->whereIn('field_name', ProfileVisibility::FIELDS)
            ->pluck('is_public', 'field_name');

        $map = ProfileVisibility::defaultMap();
        foreach (ProfileVisibility::FIELDS as $field) {
            $map[$field] = (bool) ($rows[$field] ?? $map[$field]);
        }

        return $map;
    }
}
