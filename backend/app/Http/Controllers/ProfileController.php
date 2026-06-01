<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\User;
use App\Support\ProfileVisibilityDefaults;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $profile = $this->findOrCreateProfile($user);
        $this->ensureVisibilities($user);

        return response()->json($this->profilePayload($user, $profile));
    }

    public function update(Request $request)
    {
        $visibilityRules = [];
        foreach (ProfileVisibility::FIELD_NAMES as $fieldName) {
            $visibilityRules["visibilities.{$fieldName}"] = ['required', 'boolean'];
        }

        $validated = $request->validate([
            'biography' => ['nullable', 'string'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'visibilities' => ['required', 'array'],
            ...$visibilityRules,
        ]);

        $user = $request->user();

        $profile = Profile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'biography' => $validated['biography'] ?? null,
                'occupation' => $validated['occupation'] ?? null,
                'region' => $validated['region'] ?? null,
            ]
        );

        foreach ($validated['visibilities'] as $fieldName => $isPublic) {
            ProfileVisibility::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'field_name' => $fieldName,
                ],
                ['is_public' => $isPublic]
            );
        }

        return response()->json([
            'message' => 'プロフィールを更新しました。',
            ...$this->profilePayload($user, $profile->fresh()),
        ]);
    }

    private function findOrCreateProfile(User $user): Profile
    {
        return Profile::firstOrCreate(['user_id' => $user->id]);
    }

    private function ensureVisibilities(User $user): void
    {
        $existing = $user->profileVisibilities()->pluck('field_name')->all();
        $missing = array_diff(ProfileVisibility::FIELD_NAMES, $existing);

        if ($missing === []) {
            return;
        }

        $defaults = ProfileVisibilityDefaults::values();

        foreach ($missing as $fieldName) {
            ProfileVisibility::create([
                'user_id' => $user->id,
                'field_name' => $fieldName,
                'is_public' => $defaults[$fieldName] ?? false,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(User $user, Profile $profile): array
    {
        $visibilities = $user->profileVisibilities()
            ->whereIn('field_name', ProfileVisibility::FIELD_NAMES)
            ->pluck('is_public', 'field_name');

        $orderedVisibilities = [];
        foreach (ProfileVisibility::FIELD_NAMES as $fieldName) {
            $orderedVisibilities[$fieldName] = (bool) ($visibilities[$fieldName] ?? false);
        }

        return [
            'profile' => $profile,
            'visibilities' => $orderedVisibilities,
            'user' => [
                'last_name' => $user->last_name,
                'first_name' => $user->first_name,
            ],
        ];
    }
}
