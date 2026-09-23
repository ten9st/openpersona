<?php

namespace Tests\Feature;

use App\Domain\Post\Models\Category;
use App\Domain\Post\Models\Post;
use App\Models\Follow;
use App\Models\IdentityVerification;
use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\TrustScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FollowTest extends TestCase
{
    use RefreshDatabase;

    private function createCategory(): Category
    {
        return Category::create([
            'name' => '政治',
            'slug' => 'politics',
            'sort_order' => 1,
        ]);
    }

    private function createPublishedPost(User $user, Category $category, string $title = '公開投稿'): Post
    {
        return Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => $title,
            'body' => '本文です。',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function test_guest_cannot_use_follow_endpoints(): void
    {
        $user = User::factory()->create();

        $this->postJson("/api/users/{$user->id}/follow")->assertUnauthorized();
        $this->deleteJson("/api/users/{$user->id}/follow")->assertUnauthorized();
        $this->getJson("/api/users/{$user->id}/followers")->assertUnauthorized();
        $this->getJson("/api/users/{$user->id}/following")->assertUnauthorized();
        $this->getJson('/api/timeline')->assertUnauthorized();
    }

    public function test_user_can_follow_another_user(): void
    {
        $target = User::factory()->create();
        $follower = User::factory()->create();

        Sanctum::actingAs($follower);

        $this->postJson("/api/users/{$target->id}/follow")
            ->assertOk()
            ->assertJsonPath('is_following', true)
            ->assertJsonPath('followers_count', 1);

        $this->assertDatabaseHas('follows', [
            'follower_user_id' => $follower->id,
            'followed_user_id' => $target->id,
        ]);
    }

    public function test_user_cannot_follow_self(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson("/api/users/{$user->id}/follow")
            ->assertForbidden()
            ->assertJsonPath('message', '自分自身をフォローすることはできません。');
    }

    public function test_duplicate_follow_is_ignored(): void
    {
        $target = User::factory()->create();
        $follower = User::factory()->create();

        Sanctum::actingAs($follower);

        $this->postJson("/api/users/{$target->id}/follow")->assertOk();
        $this->postJson("/api/users/{$target->id}/follow")
            ->assertOk()
            ->assertJsonPath('followers_count', 1);

        $this->assertSame(1, Follow::query()->count());
    }

    public function test_user_can_unfollow(): void
    {
        $target = User::factory()->create();
        $follower = User::factory()->create();

        Follow::create([
            'follower_user_id' => $follower->id,
            'followed_user_id' => $target->id,
        ]);

        Sanctum::actingAs($follower);

        $this->deleteJson("/api/users/{$target->id}/follow")
            ->assertOk()
            ->assertJsonPath('is_following', false)
            ->assertJsonPath('followers_count', 0);

        $this->assertDatabaseMissing('follows', [
            'follower_user_id' => $follower->id,
            'followed_user_id' => $target->id,
        ]);
    }

    public function test_unfollowing_without_existing_follow_succeeds(): void
    {
        $target = User::factory()->create();
        $follower = User::factory()->create();

        Sanctum::actingAs($follower);

        $this->deleteJson("/api/users/{$target->id}/follow")
            ->assertOk()
            ->assertJsonPath('is_following', false)
            ->assertJsonPath('followers_count', 0)
            ->assertJsonPath('following_count', 0);
    }

    public function test_repeated_unfollow_succeeds(): void
    {
        $target = User::factory()->create();
        $follower = User::factory()->create();

        Follow::create([
            'follower_user_id' => $follower->id,
            'followed_user_id' => $target->id,
        ]);

        Sanctum::actingAs($follower);

        $this->deleteJson("/api/users/{$target->id}/follow")->assertOk();
        $this->deleteJson("/api/users/{$target->id}/follow")
            ->assertOk()
            ->assertJsonPath('is_following', false)
            ->assertJsonPath('followers_count', 0);

        $this->assertSame(0, Follow::query()->count());
    }

    public function test_follow_and_unfollow_do_not_affect_other_users_relationships(): void
    {
        $target = User::factory()->create();
        $follower = User::factory()->create();

        $otherFollower = User::factory()->create();
        $otherTarget = User::factory()->create();

        Follow::create([
            'follower_user_id' => $otherFollower->id,
            'followed_user_id' => $otherTarget->id,
        ]);

        Sanctum::actingAs($follower);

        $this->postJson("/api/users/{$target->id}/follow")->assertOk();

        $this->assertDatabaseHas('follows', [
            'follower_user_id' => $otherFollower->id,
            'followed_user_id' => $otherTarget->id,
        ]);

        $this->deleteJson("/api/users/{$target->id}/follow")->assertOk();

        $this->assertDatabaseHas('follows', [
            'follower_user_id' => $otherFollower->id,
            'followed_user_id' => $otherTarget->id,
        ]);
        $this->assertSame(1, Follow::query()->count());
    }

    public function test_follow_response_counts_reflect_target_user_not_actor(): void
    {
        $target = User::factory()->create();
        $follower = User::factory()->create();

        // 操作者(follower)自身のフォロワー/フォロー数を、対象(target)とは
        // 異なる値にしておく。
        $followerOfFollower = User::factory()->create();
        Follow::create([
            'follower_user_id' => $followerOfFollower->id,
            'followed_user_id' => $follower->id,
        ]);
        $someoneFollowerFollows = User::factory()->create();
        Follow::create([
            'follower_user_id' => $follower->id,
            'followed_user_id' => $someoneFollowerFollows->id,
        ]);

        // targetには他に2人のフォロワーがいる状態にしておく。
        $otherFollowerOfTarget1 = User::factory()->create();
        $otherFollowerOfTarget2 = User::factory()->create();
        Follow::create([
            'follower_user_id' => $otherFollowerOfTarget1->id,
            'followed_user_id' => $target->id,
        ]);
        Follow::create([
            'follower_user_id' => $otherFollowerOfTarget2->id,
            'followed_user_id' => $target->id,
        ]);

        Sanctum::actingAs($follower);

        $this->postJson("/api/users/{$target->id}/follow")
            ->assertOk()
            ->assertJsonPath('followers_count', 3)
            ->assertJsonPath('following_count', 0);

        $this->deleteJson("/api/users/{$target->id}/follow")
            ->assertOk()
            ->assertJsonPath('followers_count', 2)
            ->assertJsonPath('following_count', 0);
    }

    public function test_user_can_list_followers_and_following(): void
    {
        $target = User::factory()->create(['last_name' => '対象']);
        $follower = User::factory()->create(['last_name' => 'フォロワー']);
        $followed = User::factory()->create(['last_name' => 'フォロイー']);

        Follow::create([
            'follower_user_id' => $follower->id,
            'followed_user_id' => $target->id,
        ]);
        Follow::create([
            'follower_user_id' => $target->id,
            'followed_user_id' => $followed->id,
        ]);

        Sanctum::actingAs($follower);

        $this->getJson("/api/users/{$target->id}/followers")
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', $follower->id)
            ->assertJsonPath('users.0.last_name', 'フォロワー');

        $this->getJson("/api/users/{$target->id}/following")
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', $followed->id)
            ->assertJsonPath('users.0.last_name', 'フォロイー');
    }

    public function test_followers_and_following_lists_are_ordered_newest_first(): void
    {
        $target = User::factory()->create();

        // Follow::$fillableにcreated_atが含まれないため、create()への
        // 直接指定ではなくforceFill()で明示的に順序をずらす。
        $firstFollower = User::factory()->create(['last_name' => '一番目']);
        Follow::create([
            'follower_user_id' => $firstFollower->id,
            'followed_user_id' => $target->id,
        ])->forceFill(['created_at' => now()->subMinutes(2)])->save();

        $secondFollower = User::factory()->create(['last_name' => '二番目']);
        Follow::create([
            'follower_user_id' => $secondFollower->id,
            'followed_user_id' => $target->id,
        ])->forceFill(['created_at' => now()->subMinute()])->save();

        $firstFollowed = User::factory()->create(['last_name' => '三番目']);
        Follow::create([
            'follower_user_id' => $target->id,
            'followed_user_id' => $firstFollowed->id,
        ])->forceFill(['created_at' => now()->subMinutes(2)])->save();

        $secondFollowed = User::factory()->create(['last_name' => '四番目']);
        Follow::create([
            'follower_user_id' => $target->id,
            'followed_user_id' => $secondFollowed->id,
        ])->forceFill(['created_at' => now()->subMinute()])->save();

        Sanctum::actingAs($target);

        $this->getJson("/api/users/{$target->id}/followers")
            ->assertOk()
            ->assertJsonPath('users.0.last_name', '二番目')
            ->assertJsonPath('users.1.last_name', '一番目');

        $this->getJson("/api/users/{$target->id}/following")
            ->assertOk()
            ->assertJsonPath('users.0.last_name', '四番目')
            ->assertJsonPath('users.1.last_name', '三番目');
    }

    public function test_followers_and_following_lists_respect_visibility_settings(): void
    {
        $target = User::factory()->create();

        $follower = User::factory()->create(['first_name' => '非公開名']);
        Profile::create(['user_id' => $follower->id]);
        ProfileVisibility::create([
            'user_id' => $follower->id,
            'field_name' => 'first_name',
            'is_public' => false,
        ]);

        Follow::create([
            'follower_user_id' => $follower->id,
            'followed_user_id' => $target->id,
        ]);

        Sanctum::actingAs($target);

        $this->getJson("/api/users/{$target->id}/followers")
            ->assertOk()
            ->assertJsonPath('users.0.id', $follower->id)
            ->assertJsonPath('users.0.first_name', null);
    }

    public function test_following_list_shows_correct_max_score_without_per_user_query_increase(): void
    {
        $target = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $followed = User::factory()->create();

            IdentityVerification::create([
                'user_id' => $followed->id,
                'verification_method' => 'driver_license',
                'verification_status' => IdentityVerification::STATUS_VERIFIED,
                'verified_at' => now(),
            ]);

            // 本人確認前のTrustScore(max_score=未確認)が同期されずに
            // 残っている状態を再現する。
            TrustScore::where('user_id', $followed->id)->update([
                'max_score' => TrustScore::MAX_SCORE_UNVERIFIED,
            ]);

            Follow::create([
                'follower_user_id' => $target->id,
                'followed_user_id' => $followed->id,
            ]);
        }

        Sanctum::actingAs($target);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->getJson("/api/users/{$target->id}/following");

        $queryCountForThreeFollowed = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()->assertJsonCount(3, 'users');

        foreach ($response->json('users') as $userPayload) {
            $this->assertTrue($userPayload['identity_verified'], '本人確認状態が正しく反映されていません。');
            $this->assertSame(
                TrustScore::MAX_SCORE_VERIFIED,
                $userPayload['trust_score']['max_score'],
                'max_scoreが本人確認済みユーザーの表示として正しくありません。'
            );
        }

        $extraFollowed = User::factory()->create();
        Follow::create([
            'follower_user_id' => $target->id,
            'followed_user_id' => $extraFollowed->id,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson("/api/users/{$target->id}/following")->assertOk();
        $queryCountForFourFollowed = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $queryCountForThreeFollowed,
            $queryCountForFourFollowed,
            'フォロー先数の増加に応じてクエリ数が増加しています(N+1の可能性があります)。'
        );
    }

    public function test_followers_list_shows_correct_max_score_without_per_user_query_increase(): void
    {
        $target = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $follower = User::factory()->create();

            IdentityVerification::create([
                'user_id' => $follower->id,
                'verification_method' => 'driver_license',
                'verification_status' => IdentityVerification::STATUS_VERIFIED,
                'verified_at' => now(),
            ]);

            // 本人確認前のTrustScore(max_score=未確認)が同期されずに
            // 残っている状態を再現する。
            TrustScore::where('user_id', $follower->id)->update([
                'max_score' => TrustScore::MAX_SCORE_UNVERIFIED,
            ]);

            Follow::create([
                'follower_user_id' => $follower->id,
                'followed_user_id' => $target->id,
            ]);
        }

        Sanctum::actingAs($target);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->getJson("/api/users/{$target->id}/followers");

        $queryCountForThreeFollowers = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()->assertJsonCount(3, 'users');

        foreach ($response->json('users') as $userPayload) {
            $this->assertTrue($userPayload['identity_verified'], '本人確認状態が正しく反映されていません。');
            $this->assertSame(
                TrustScore::MAX_SCORE_VERIFIED,
                $userPayload['trust_score']['max_score'],
                'max_scoreが本人確認済みユーザーの表示として正しくありません。'
            );
        }

        // フォロワーが1人増えても、クエリ数が比例して増えない(N+1になって
        // いない)ことを確認する。
        $extraFollower = User::factory()->create();
        Follow::create([
            'follower_user_id' => $extraFollower->id,
            'followed_user_id' => $target->id,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson("/api/users/{$target->id}/followers")->assertOk();
        $queryCountForFourFollowers = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $queryCountForThreeFollowers,
            $queryCountForFourFollowers,
            'フォロワー数の増加に応じてクエリ数が増加しています(N+1の可能性があります)。'
        );
    }

    public function test_timeline_returns_followed_users_posts_in_latest_order(): void
    {
        $viewer = User::factory()->create();
        $followed = User::factory()->create();
        $other = User::factory()->create();
        $category = $this->createCategory();

        Follow::create([
            'follower_user_id' => $viewer->id,
            'followed_user_id' => $followed->id,
        ]);

        $older = $this->createPublishedPost($followed, $category, '古い投稿');
        $older->update(['published_at' => now()->subDay()]);

        $newer = $this->createPublishedPost($followed, $category, '新しい投稿');
        $newer->update(['published_at' => now()]);

        $this->createPublishedPost($other, $category, '他人の投稿');

        Sanctum::actingAs($viewer);

        $this->getJson('/api/timeline')
            ->assertOk()
            ->assertJsonCount(2, 'posts')
            ->assertJsonPath('posts.0.title', '新しい投稿')
            ->assertJsonPath('posts.1.title', '古い投稿');
    }

    public function test_timeline_returns_empty_when_not_following_anyone(): void
    {
        $viewer = User::factory()->create();

        Sanctum::actingAs($viewer);

        $this->getJson('/api/timeline')
            ->assertOk()
            ->assertJsonPath('posts', []);
    }

    public function test_timeline_excludes_drafts_and_deleted_posts_from_followed_user(): void
    {
        $viewer = User::factory()->create();
        $followed = User::factory()->create();
        $category = $this->createCategory();

        Follow::create([
            'follower_user_id' => $viewer->id,
            'followed_user_id' => $followed->id,
        ]);

        Post::create([
            'user_id' => $followed->id,
            'category_id' => $category->id,
            'title' => '下書き',
            'body' => '本文',
            'status' => 'draft',
        ]);

        $deleted = $this->createPublishedPost($followed, $category, '削除済み投稿');
        $deleted->update(['status' => 'deleted']);

        $published = $this->createPublishedPost($followed, $category, '公開投稿');

        Sanctum::actingAs($viewer);

        $this->getJson('/api/timeline')
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('posts.0.title', $published->title);
    }

    public function test_timeline_returns_empty_when_followed_user_has_no_published_posts(): void
    {
        $viewer = User::factory()->create();
        $followed = User::factory()->create();
        $category = $this->createCategory();

        Follow::create([
            'follower_user_id' => $viewer->id,
            'followed_user_id' => $followed->id,
        ]);

        Post::create([
            'user_id' => $followed->id,
            'category_id' => $category->id,
            'title' => '下書き',
            'body' => '本文',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/timeline')
            ->assertOk()
            ->assertJsonPath('posts', []);
    }

    public function test_public_profile_includes_follow_counts_and_is_following(): void
    {
        $target = User::factory()->create();
        $follower = User::factory()->create();
        $other = User::factory()->create();

        Follow::create([
            'follower_user_id' => $follower->id,
            'followed_user_id' => $target->id,
        ]);
        Follow::create([
            'follower_user_id' => $target->id,
            'followed_user_id' => $other->id,
        ]);

        $token = $follower->createToken('openpersona_token')->plainTextToken;

        $this->getJson("/api/users/{$target->id}", [
            'Authorization' => "Bearer {$token}",
        ])
            ->assertOk()
            ->assertJsonPath('followers_count', 1)
            ->assertJsonPath('following_count', 1)
            ->assertJsonPath('is_following', true);

        $this->getJson("/api/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('followers_count', 1)
            ->assertJsonPath('following_count', 1)
            ->assertJsonMissingPath('is_following');
    }
}
