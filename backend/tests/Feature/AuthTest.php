<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_logout_and_revoke_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('openpersona_token')->plainTextToken;

        $this->postJson('/api/logout', [], [
            'Authorization' => "Bearer {$token}",
        ])
            ->assertOk()
            ->assertJsonPath('message', 'ログアウトしました。');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_guest_cannot_logout(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_revoked_token_cannot_access_protected_routes(): void
    {
        $user = User::factory()->create();
        $accessToken = $user->createToken('openpersona_token');
        $plainTextToken = $accessToken->plainTextToken;

        $this->postJson('/api/logout', [], [
            'Authorization' => "Bearer {$plainTextToken}",
        ])->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($plainTextToken));

        Auth::forgetGuards();

        $this->getJson('/api/me', [
            'Authorization' => "Bearer {$plainTextToken}",
        ])->assertUnauthorized();
    }
}
