<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $profile = $this->findOrCreateProfile($request->user());

        return response()->json([
            'profile' => $profile,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'display_last_name' => ['required', 'string', 'max:255'],
            'display_first_name' => ['nullable', 'string', 'max:255'],
            'age_public' => ['required', 'boolean'],
            'full_name_public' => ['required', 'boolean'],
            'biography' => ['nullable', 'string'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'occupation_public' => ['required', 'boolean'],
            'region' => ['nullable', 'string', 'max:255'],
            'region_public' => ['required', 'boolean'],
        ]);

        $profile = Profile::updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated + ['user_id' => $request->user()->id]
        );

        return response()->json([
            'message' => 'プロフィールを更新しました。',
            'profile' => $profile,
        ]);
    }

    private function findOrCreateProfile(User $user): Profile
    {
        return Profile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'display_last_name' => $user->last_name,
                'display_first_name' => $user->first_name,
                'age_public' => true,
                'full_name_public' => false,
            ]
        );
    }
}
