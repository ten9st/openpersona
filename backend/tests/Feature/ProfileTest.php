<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
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
            ->assertJsonPath('profile.display_last_name', $user->last_name)
            ->assertJsonPath('profile.display_first_name', $user->first_name);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_update_profile(): void
    {
        $user = User::factory()->create();
        Profile::create([
            'user_id' => $user->id,
            'display_last_name' => $user->last_name,
            'display_first_name' => $user->first_name,
            'age_public' => true,
            'full_name_public' => false,
        ]);

        Sanctum::actingAs($user);

        $payload = [
            'display_last_name' => '公開',
            'display_first_name' => '太郎',
            'age_public' => false,
            'full_name_public' => true,
            'biography' => '自己紹介です。',
            'occupation' => 'エンジニア',
            'occupation_public' => true,
            'region' => '東京',
            'region_public' => false,
        ];

        $response = $this->putJson('/api/profile', $payload);

        $response->assertOk()
            ->assertJsonPath('message', 'プロフィールを更新しました。')
            ->assertJsonPath('profile.display_last_name', '公開')
            ->assertJsonPath('profile.biography', '自己紹介です。');

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'display_last_name' => '公開',
            'occupation' => 'エンジニア',
            'age_public' => 0,
        ]);
    }
}
