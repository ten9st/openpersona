<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\User;
use App\Support\ProfileVisibilityDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_profile(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->putJson('/api/profile', [])->assertUnauthorized();
    }

    public function test_user_can_show_profile(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/profile');

        $response->assertOk()
            ->assertJsonPath('profile.user_id', $user->id)
            ->assertJsonPath('user.last_name', $user->last_name)
            ->assertJsonPath('user.first_name', $user->first_name)
            ->assertJsonPath('visibilities.age', true)
            ->assertJsonPath('visibilities.full_name', false);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
        ]);

        foreach (ProfileVisibility::FIELD_NAMES as $fieldName) {
            $this->assertDatabaseHas('profile_visibilities', [
                'user_id' => $user->id,
                'field_name' => $fieldName,
            ]);
        }
    }

    public function test_user_can_update_profile(): void
    {
        $user = User::factory()->create();
        Profile::create(['user_id' => $user->id]);
        ProfileVisibilityDefaults::seedForUser($user->id);

        Sanctum::actingAs($user);

        $payload = [
            'biography' => '自己紹介です。',
            'occupation' => 'エンジニア',
            'region' => '東京',
            'visibilities' => [
                'last_name' => false,
                'first_name' => false,
                'full_name' => true,
                'age' => false,
                'biography' => true,
                'occupation' => true,
                'region' => false,
            ],
        ];

        $response = $this->putJson('/api/profile', $payload);

        $response->assertOk()
            ->assertJsonPath('message', 'プロフィールを更新しました。')
            ->assertJsonPath('profile.biography', '自己紹介です。')
            ->assertJsonPath('visibilities.full_name', true)
            ->assertJsonPath('visibilities.age', false);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'occupation' => 'エンジニア',
        ]);

        $this->assertDatabaseHas('profile_visibilities', [
            'user_id' => $user->id,
            'field_name' => 'full_name',
            'is_public' => 1,
        ]);
    }
}
