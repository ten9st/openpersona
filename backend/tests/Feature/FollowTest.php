<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Follow;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->postJson("/api/users/{$user->id}/follow")->assertForbidden();
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
