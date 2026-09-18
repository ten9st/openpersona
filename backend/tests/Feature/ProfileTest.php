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
            ->assertJsonPath('meta.basic_info_locked.email', true)
            ->assertJsonPath('meta.basic_info_locked.birthdate', true)
            ->assertJsonPath('meta.basic_info_locked.last_name', false)
            ->assertJsonPath('meta.basic_info_locked.first_name', false)
            ->assertJsonPath('meta.identity_verified', false)
            ->assertJsonPath('trust_score.max_score', TrustScore::MAX_SCORE_UNVERIFIED)
            ->assertJsonPath('user.last_name', $user->last_name)
            ->assertJsonPath('user.first_name', $user->first_name)
            ->assertJsonPath('visibilities.first_name', false)
            ->assertJsonPath('visibilities.biography', false)
            ->assertJsonPath('visibilities.occupation', false)
            ->assertJsonMissingPath('visibilities.last_name')
            ->assertJsonMissingPath('visibilities.age')
            ->assertJsonMissingPath('visibilities.region')
            ->assertJsonPath('educations', [])
            ->assertJsonPath('careers', []);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('profile_visibilities', [
            'user_id' => $user->id,
            'field_name' => 'first_name',
            'is_public' => 0,
        ]);

        $this->assertDatabaseMissing('profile_visibilities', [
            'user_id' => $user->id,
            'field_name' => 'last_name',
        ]);
    }

    public function test_user_can_update_profile_with_educations_and_careers(): void
    {
        $user = User::factory()->create();
        Profile::create(['user_id' => $user->id]);

        foreach (ProfileVisibility::defaultMap() as $fieldName => $isPublic) {
            ProfileVisibility::create([
                'user_id' => $user->id,
                'field_name' => $fieldName,
                'is_public' => $isPublic,
            ]);
        }

        UserEducation::create([
            'user_id' => $user->id,
            'school_name' => '旧大学',
            'sort_order' => 0,
        ]);

        Sanctum::actingAs($user);

        $payload = [
            'last_name' => '公開',
            'first_name' => '太郎',
            'biography' => '自己紹介です。',
            'occupation' => 'エンジニア',
            'region' => '東京都',
            'visibilities' => [
                'first_name' => true,
                'biography' => true,
                'occupation' => true,
            ],
            'educations' => [
                [
                    'school_name' => '東京大学',
                    'faculty' => '工学部',
                    'degree' => '学士',
                    'start_year' => 2010,
                    'end_year' => 2014,
                    'is_public' => true,
                ],
            ],
            'careers' => [
                [
                    'company_name' => 'A社',
                    'position' => 'エンジニア',
                    'start_year' => 2015,
                    'end_year' => 2019,
                    'is_current' => false,
                    'is_public' => true,
                ],
                [
                    'company_name' => 'B社',
                    'position' => 'シニアエンジニア',
                    'start_year' => 2020,
                    'end_year' => null,
                    'is_current' => true,
                    'is_public' => false,
                ],
            ],
        ];

        $response = $this->putJson('/api/profile', $payload);

        $response->assertOk()
            ->assertJsonPath('message', 'プロフィールを更新しました。')
            ->assertJsonPath('user.last_name', '公開')
            ->assertJsonPath('profile.biography', '自己紹介です。')
            ->assertJsonPath('profile.region', '東京都')
            ->assertJsonPath('visibilities.first_name', true)
            ->assertJsonPath('educations.0.school_name', '東京大学')
            ->assertJsonPath('careers.1.is_current', true)
            ->assertJsonPath('careers.1.end_year', null);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'last_name' => '公開',
        ]);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'occupation' => 'エンジニア',
            'region' => '東京都',
        ]);

        $this->assertDatabaseHas('profile_visibilities', [
            'user_id' => $user->id,
            'field_name' => 'first_name',
            'is_public' => 1,
        ]);

        $this->assertDatabaseHas('user_educations', [
            'user_id' => $user->id,
            'school_name' => '東京大学',
            'sort_order' => 0,
        ]);

        $this->assertDatabaseMissing('user_educations', [
            'user_id' => $user->id,
            'school_name' => '旧大学',
        ]);

        $this->assertDatabaseHas('user_careers', [
            'user_id' => $user->id,
            'company_name' => 'B社',
            'is_current' => 1,
            'end_year' => null,
            'sort_order' => 1,
        ]);

        $this->assertSame(1, UserEducation::where('user_id', $user->id)->count());
        $this->assertSame(2, UserCareer::where('user_id', $user->id)->count());
    }

    public function test_user_cannot_set_invalid_prefecture(): void
    {
        $user = User::factory()->create();
        Profile::create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->putJson('/api/profile', [
            'last_name' => $user->last_name,
            'first_name' => $user->first_name,
            'region' => '東京',
            'visibilities' => ProfileVisibility::defaultMap(),
            'educations' => [],
            'careers' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['region']);
    }

    public function test_user_cannot_update_profile_with_invalid_basic_info(): void
    {
        $user = User::factory()->create();
        Profile::create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $basePayload = [
            'biography' => null,
            'occupation' => null,
            'visibilities' => ProfileVisibility::defaultMap(),
            'educations' => [],
            'careers' => [],
        ];

        $this->putJson('/api/profile', [
            ...$basePayload,
            'last_name' => '',
            'first_name' => '太郎',
            'region' => '東京都',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['last_name']);

        $this->putJson('/api/profile', [
            ...$basePayload,
            'last_name' => '山田123',
            'first_name' => '太郎',
            'region' => '東京都',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['last_name']);

        $this->putJson('/api/profile', [
            ...$basePayload,
            'last_name' => '山田',
            'first_name' => '太郎',
            'region' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['region']);
    }

    public function test_user_cannot_change_birthdate_via_profile_update(): void
    {
        $user = User::factory()->create([
            'birthdate' => '1990-01-01',
        ]);
        Profile::create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->putJson('/api/profile', [
            'last_name' => $user->last_name,
            'first_name' => $user->first_name,
            'birthdate' => '1995-06-15',
            'region' => '東京都',
            'visibilities' => ProfileVisibility::defaultMap(),
            'educations' => [],
            'careers' => [],
        ])
            ->assertOk();

        $user->refresh();
        $this->assertSame('1990-01-01', $user->birthdate->format('Y-m-d'));
    }

    public function test_verified_user_cannot_change_locked_basic_info(): void
    {
        $user = User::factory()->create([
            'last_name' => '山田',
            'first_name' => '太郎',
            'birthdate' => '1990-01-01',
        ]);
        Profile::create(['user_id' => $user->id, 'region' => '東京都']);

        IdentityVerification::create([
            'user_id' => $user->id,
            'verification_method' => 'driver_license',
            'verification_status' => IdentityVerification::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        TrustScore::ensureForUser($user);

        Sanctum::actingAs($user);

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('meta.basic_info_locked.email', true)
            ->assertJsonPath('meta.basic_info_locked.birthdate', true)
            ->assertJsonPath('meta.basic_info_locked.last_name', true)
            ->assertJsonPath('meta.basic_info_locked.first_name', true)
            ->assertJsonPath('meta.identity_verified', true)
            ->assertJsonPath('trust_score.max_score', TrustScore::MAX_SCORE_VERIFIED);

        $this->putJson('/api/profile', [
            'last_name' => '変更',
            'first_name' => '太郎',
            'region' => '東京都',
            'visibilities' => ProfileVisibility::defaultMap(),
            'educations' => [],
            'careers' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['last_name']);
    }

    public function test_verified_user_can_update_region_and_profile_fields(): void
    {
        $user = User::factory()->create([
            'last_name' => '山田',
            'first_name' => '太郎',
            'birthdate' => '1990-01-01',
        ]);
        Profile::create([
            'user_id' => $user->id,
            'region' => '東京都',
            'biography' => '旧',
            'occupation' => '旧職',
        ]);

        IdentityVerification::create([
            'user_id' => $user->id,
            'verification_method' => 'driver_license',
            'verification_status' => IdentityVerification::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/profile', [
            'last_name' => '山田',
            'first_name' => '太郎',
            'region' => '大阪府',
            'biography' => '新しい自己紹介',
            'occupation' => '新職',
            'visibilities' => ProfileVisibility::defaultMap(),
            'educations' => [],
            'careers' => [],
        ])
            ->assertOk()
            ->assertJsonPath('profile.region', '大阪府')
            ->assertJsonPath('profile.biography', '新しい自己紹介')
            ->assertJsonPath('profile.occupation', '新職');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'last_name' => '山田',
        ]);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'region' => '大阪府',
            'occupation' => '新職',
        ]);
    }
}
