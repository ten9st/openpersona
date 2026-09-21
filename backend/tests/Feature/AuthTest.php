<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\TrustScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * 同一テスト内の複数リクエスト間で残る認証ガードのユーザー、既定ガード
     * (auth:sanctumが切り替える)、セッションストア上のログイン状態をリセットし、
     * Bearerトークン自体による認証を検証するための補助処理。
     * 本番のCookie通信・実行環境での挙動は、このテストでは確認していない。
     */
    private function resetAuthState(): void
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->flushSession();
    }

    /**
     * @return array<string, string>
     */
    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'taro@example.com',
            'password' => 'password123',
            'last_name' => '山田',
            'first_name' => '太郎',
            'birthdate' => '1990-01-01',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // 登録: 成功
    // ------------------------------------------------------------------

    public function test_register_returns_201_with_existing_json_shape(): void
    {
        $response = $this->postJson('/api/register', $this->registerPayload())
            ->assertCreated()
            ->assertJsonPath('message', 'ユーザー登録が完了しました。');

        $json = $response->json();
        $this->assertSame(['message', 'user'], array_keys($json));

        // userはUserの属性に加え、UserObserver経由のTrustScoreService::calculate()が
        // 読み込んだリレーション(profile/educations/careers)もシリアライズされる。
        // profileはProfile作成より前に読み込まれるためnullのまま。
        $user = $json['user'];
        $keys = array_keys($user);
        sort($keys);
        $this->assertSame([
            'birthdate', 'careers', 'created_at', 'educations', 'email',
            'first_name', 'id', 'last_name', 'profile', 'updated_at',
        ], $keys);

        $this->assertIsInt($user['id']);
        $this->assertSame('taro@example.com', $user['email']);
        $this->assertSame('山田', $user['last_name']);
        $this->assertSame('太郎', $user['first_name']);
        $this->assertMatchesRegularExpression('/^1990-01-01T/', $user['birthdate']);
        $this->assertIsString($user['created_at']);
        $this->assertIsString($user['updated_at']);
        $this->assertNull($user['profile']);
        $this->assertSame([], $user['educations']);
        $this->assertSame([], $user['careers']);

        $this->assertArrayNotHasKey('password', $user);
        $this->assertArrayNotHasKey('remember_token', $user);
        $this->assertStringNotContainsString('password123', $response->getContent());
        $this->assertSame(User::firstOrFail()->id, $user['id']);
    }

    public function test_register_creates_profile_visibilities_and_trust_score(): void
    {
        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        $user = User::firstOrFail();

        $this->assertSame(1, User::count());
        $this->assertSame(1, Profile::count());
        $profile = Profile::firstOrFail();
        $this->assertSame($user->id, $profile->user_id);
        $this->assertNull($profile->region);
        $this->assertNull($profile->biography);
        $this->assertNull($profile->occupation);

        $this->assertSame(3, ProfileVisibility::count());
        $this->assertSame(
            ['first_name' => false, 'biography' => false, 'occupation' => false],
            ProfileVisibility::where('user_id', $user->id)->orderBy('id')->pluck('is_public', 'field_name')
                ->map(fn ($v) => (bool) $v)->all(),
        );
        $this->assertSame(
            ['first_name', 'biography', 'occupation'],
            ProfileVisibility::where('user_id', $user->id)->orderBy('id')->pluck('field_name')->all(),
        );

        $this->assertSame(1, TrustScore::count());
        $trustScore = TrustScore::firstOrFail();
        $this->assertSame($user->id, $trustScore->user_id);
        $this->assertSame(0, $trustScore->profile_score);
        $this->assertSame(0, $trustScore->posting_score);
        $this->assertSame(0, $trustScore->source_score);
        $this->assertSame(0, $trustScore->history_score);
        $this->assertSame(0, $trustScore->total_score);
        $this->assertSame(TrustScore::MAX_SCORE_UNVERIFIED, $trustScore->max_score);
    }

    public function test_register_stores_hashed_password_and_allows_login(): void
    {
        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        $stored = User::firstOrFail()->getAuthPassword();
        $this->assertNotSame('password123', $stored);
        $this->assertTrue(Hash::check('password123', $stored));
        $this->assertFalse(Hash::check('password124', $stored));

        $this->postJson('/api/login', [
            'email' => 'taro@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('user.email', 'taro@example.com');
    }

    public function test_register_trims_input_before_validation(): void
    {
        $this->postJson('/api/register', $this->registerPayload([
            'last_name' => '  山田 ',
            'first_name' => "\t太郎\n",
            'birthdate' => ' 1990-01-01 ',
        ]))->assertCreated();

        $user = User::firstOrFail();
        $this->assertSame('山田', $user->last_name);
        $this->assertSame('太郎', $user->first_name);
        $this->assertSame('1990-01-01', $user->birthdate->format('Y-m-d'));
    }

    // ------------------------------------------------------------------
    // 登録: 入力検証(失敗時は何も作られない)
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidRegisterPayloads(): array
    {
        return [
            'email missing' => [['email' => null], 'email'],
            'email format' => [['email' => 'not-an-email'], 'email'],
            'email duplicate' => [['email' => 'existing@example.com'], 'email'],
            'password missing' => [['password' => null], 'password'],
            'password 7 chars' => [['password' => 'a234567'], 'password'],
            'last_name missing' => [['last_name' => null], 'last_name'],
            'last_name digits' => [['last_name' => '山田1'], 'last_name'],
            'last_name 51 chars' => [['last_name' => str_repeat('a', 51)], 'last_name'],
            'first_name missing' => [['first_name' => null], 'first_name'],
            'first_name symbol' => [['first_name' => '太郎!'], 'first_name'],
            'birthdate missing' => [['birthdate' => null], 'birthdate'],
            'birthdate slash format' => [['birthdate' => '1990/01/01'], 'birthdate'],
            'birthdate not a date' => [['birthdate' => 'abcd-ef-gh'], 'birthdate'],
            'birthdate too young' => [['birthdate' => '__YOUNG__'], 'birthdate'],
            'birthdate too old' => [['birthdate' => '1800-01-01'], 'birthdate'],
        ];
    }

    #[DataProvider('invalidRegisterPayloads')]
    public function test_register_rejects_invalid_input_without_creating_anything(array $overrides, string $errorKey): void
    {
        User::factory()->create(['email' => 'existing@example.com']);
        $baseline = [User::count(), Profile::count(), ProfileVisibility::count(), TrustScore::count()];

        if (($overrides['birthdate'] ?? null) === '__YOUNG__') {
            $overrides['birthdate'] = now()->subYears(12)->format('Y-m-d');
        }

        $this->postJson('/api/register', $this->registerPayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKey);

        $this->assertSame($baseline, [User::count(), Profile::count(), ProfileVisibility::count(), TrustScore::count()]);
    }

    public function test_register_accepts_password_of_exactly_8_chars(): void
    {
        $this->postJson('/api/register', $this->registerPayload(['password' => 'a2345678']))
            ->assertCreated();
    }

    public function test_register_uses_custom_messages_for_basic_info(): void
    {
        $this->postJson('/api/register', $this->registerPayload(['last_name' => '  ']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.last_name.0', '姓を入力してください。');
    }

    // ------------------------------------------------------------------
    // ログイン
    // ------------------------------------------------------------------

    public function test_login_returns_existing_json_and_issues_one_token(): void
    {
        $user = User::factory()->create(['email' => 'login@example.com']);

        $response = $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('message', 'ログインが成功しました。');

        $json = $response->json();
        $this->assertSame(['message', 'token', 'user'], array_keys($json));
        $this->assertIsString($json['token']);
        $this->assertNotSame('', $json['token']);

        $this->assertSame(
            ['id', 'email', 'last_name', 'first_name', 'birthdate'],
            array_keys($json['user']),
        );
        $this->assertSame($user->id, $json['user']['id']);
        $this->assertSame('login@example.com', $json['user']['email']);
        $this->assertSame($user->last_name, $json['user']['last_name']);
        $this->assertSame($user->first_name, $json['user']['first_name']);
        $this->assertIsString($json['user']['birthdate']);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('openpersona_token', $user->tokens()->firstOrFail()->name);
        $this->assertStringNotContainsString('password', json_encode($json['user']));
    }

    public function test_issued_token_authenticates_me_and_web_guard_state_does_not_leak(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com']);

        $token = $this->postJson('/api/login', [
            'email' => 'me@example.com',
            'password' => 'password',
        ])->assertOk()->json('token');

        // ログイン直後はこのプロセスのwebガードにログイン状態が残っている。
        // ガード状態を初期化し、トークンなしでは通らないことを先に確認する。
        $this->resetAuthState();
        $this->getJson('/api/me')->assertUnauthorized();

        $this->resetAuthState();
        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$token.'x'])->assertUnauthorized();

        $this->resetAuthState();
        $response = $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertOk();

        $me = $response->json('user');
        $this->assertSame(['user'], array_keys($response->json()));
        $keys = array_keys($me);
        sort($keys);
        $this->assertSame([
            'birthdate', 'created_at', 'email', 'email_verified_at',
            'first_name', 'id', 'last_name', 'updated_at',
        ], $keys);
        $this->assertSame($user->id, $me['id']);
        $this->assertSame('me@example.com', $me['email']);
        $this->assertArrayNotHasKey('password', $me);
        $this->assertArrayNotHasKey('remember_token', $me);
    }

    public function test_login_fails_with_same_401_for_unknown_email_and_wrong_password(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $unknown = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])->assertUnauthorized();

        $wrong = $this->postJson('/api/login', [
            'email' => 'known@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->assertSame(
            ['message' => 'メールアドレスまたはパスワードが違います。'],
            $unknown->json(),
        );
        $this->assertSame($unknown->json(), $wrong->json());
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_login_validation_failures_do_not_issue_tokens(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $this->postJson('/api/login', ['email' => 'known@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/login', ['password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/login', ['email' => 'not-an-email', 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_login_clears_only_viewed_post_session_keys(): void
    {
        User::factory()->create(['email' => 'sess@example.com']);

        $this->withSession([
            'viewed_post_1' => true,
            'viewed_post_99' => true,
            'unrelated_key' => 'keep',
        ])->postJson('/api/login', [
            'email' => 'sess@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertSessionMissing('viewed_post_1')
            ->assertSessionMissing('viewed_post_99')
            ->assertSessionHas('unrelated_key', 'keep');
    }

    public function test_failed_login_keeps_viewed_post_session_keys(): void
    {
        User::factory()->create(['email' => 'sess@example.com']);

        $this->withSession(['viewed_post_1' => true])->postJson('/api/login', [
            'email' => 'sess@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized()
            ->assertSessionHas('viewed_post_1', true);
    }

    // ------------------------------------------------------------------
    // /me
    // ------------------------------------------------------------------

    public function test_guest_cannot_get_me(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    // ------------------------------------------------------------------
    // ログアウト: 対象トークンの範囲
    // ------------------------------------------------------------------

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $tokenA = $user->createToken('openpersona_token')->plainTextToken;
        $tokenB = $user->createToken('openpersona_token')->plainTextToken;
        $tokenOther = $other->createToken('openpersona_token')->plainTextToken;

        $this->postJson('/api/logout', [], ['Authorization' => "Bearer {$tokenA}"])
            ->assertOk()
            ->assertExactJson(['message' => 'ログアウトしました。']);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
        $this->assertNull(PersonalAccessToken::findToken($tokenA));
        $this->assertNotNull(PersonalAccessToken::findToken($tokenB));

        $this->resetAuthState();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$tokenA}"])->assertUnauthorized();

        $this->resetAuthState();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$tokenB}"])
            ->assertOk()->assertJsonPath('user.id', $user->id);

        $this->resetAuthState();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$tokenOther}"])
            ->assertOk()->assertJsonPath('user.id', $other->id);
    }
}
