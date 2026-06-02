<?php

namespace Tests\Feature;

use App\Models\IdentityVerification;
use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\TrustScore;
use App\Models\User;
use App\Models\UserCareer;
use App\Models\UserEducation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_public_profile(): void
    {
        $user = User::factory()->create([
            'last_name' => '山田',
            'first_name' => '太郎',
            'birthdate' => '1990-01-01',
        ]);

        Profile::create([
            'user_id' => $user->id,
            'biography' => '自己紹介',
            'occupation' => 'エンジニア',
            'region' => '東京都',
        ]);

        ProfileVisibility::create([
            'user_id' => $user->id,
            'field_name' => 'first_name',
            'is_public' => true,
        ]);
        ProfileVisibility::create([
            'user_id' => $user->id,
            'field_name' => 'biography',
            'is_public' => true,
        ]);
        ProfileVisibility::create([
            'user_id' => $user->id,
            'field_name' => 'occupation',
            'is_public' => false,
        ]);

        UserEducation::create([
            'user_id' => $user->id,
            'school_name' => '公開大学',
            'is_public' => true,
            'sort_order' => 0,
        ]);
        UserEducation::create([
            'user_id' => $user->id,
            'school_name' => '非公開大学',
            'is_public' => false,
            'sort_order' => 1,
        ]);

        UserCareer::create([
            'user_id' => $user->id,
            'company_name' => '公開会社',
            'is_public' => true,
            'sort_order' => 0,
        ]);

        $response = $this->getJson("/api/users/{$user->id}");

        $response->assertOk()
            ->assertJsonPath('user.last_name', '山田')
            ->assertJsonPath('user.first_name', '太郎')
            ->assertJsonPath('user.region', '東京都')
            ->assertJsonPath('user.age', $user->birthdate->age)
            ->assertJsonPath('trust_score.max_score', TrustScore::MAX_SCORE_UNVERIFIED)
            ->assertJsonPath('identity_verified', false)
            ->assertJsonPath('profile.biography', '自己紹介')
            ->assertJsonPath('profile.occupation', null)
            ->assertJsonCount(1, 'educations')
            ->assertJsonPath('educations.0.school_name', '公開大学')
            ->assertJsonCount(1, 'careers')
            ->assertJsonPath('careers.0.company_name', '公開会社')
            ->assertJsonMissingPath('user.email')
            ->assertJsonMissingPath('user.birthdate');
    }

    public function test_public_profile_hides_private_first_name(): void
    {
        $user = User::factory()->create([
            'last_name' => '山田',
            'first_name' => '太郎',
        ]);

        Profile::create(['user_id' => $user->id]);

        ProfileVisibility::create([
            'user_id' => $user->id,
            'field_name' => 'first_name',
            'is_public' => false,
        ]);

        $this->getJson("/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('user.first_name', null);
    }

    public function test_public_profile_returns_not_found_for_missing_user(): void
    {
        $this->getJson('/api/users/99999')->assertNotFound();
    }

    public function test_public_profile_shows_verified_badge_and_max_score(): void
    {
        $user = User::factory()->create();
        Profile::create(['user_id' => $user->id, 'region' => '東京都']);

        IdentityVerification::create([
            'user_id' => $user->id,
            'verification_method' => 'my_number_card',
            'verification_status' => IdentityVerification::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        TrustScore::ensureForUser($user);

        $this->getJson("/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('identity_verified', true)
            ->assertJsonPath('trust_score.max_score', TrustScore::MAX_SCORE_VERIFIED);
    }
}
