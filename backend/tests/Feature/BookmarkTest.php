<?php

namespace Tests\Feature;

use App\Models\Bookmark;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookmarkTest extends TestCase
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

    public function test_guest_cannot_bookmark_post(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);

        $this->postJson("/api/posts/{$post->id}/bookmark")->assertUnauthorized();
        $this->deleteJson("/api/posts/{$post->id}/bookmark")->assertUnauthorized();
        $this->getJson('/api/bookmarks')->assertUnauthorized();
    }

    public function test_user_can_bookmark_published_post(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/posts/{$post->id}/bookmark")
            ->assertOk()
            ->assertJsonPath('is_bookmarked', true)
            ->assertJsonPath('bookmark_count', 1);

        $this->assertDatabaseHas('bookmarks', [
            'user_id' => $viewer->id,
            'post_id' => $post->id,
        ]);
    }

    public function test_duplicate_bookmark_is_ignored(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/posts/{$post->id}/bookmark")->assertOk();
        $this->postJson("/api/posts/{$post->id}/bookmark")
            ->assertOk()
            ->assertJsonPath('bookmark_count', 1);

        $this->assertSame(1, Bookmark::query()->count());
    }

    public function test_user_can_remove_bookmark(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Bookmark::create([
            'user_id' => $viewer->id,
            'post_id' => $post->id,
        ]);

        Sanctum::actingAs($viewer);

        $this->deleteJson("/api/posts/{$post->id}/bookmark")
            ->assertOk()
            ->assertJsonPath('is_bookmarked', false)
            ->assertJsonPath('bookmark_count', 0);

        $this->assertDatabaseMissing('bookmarks', [
            'user_id' => $viewer->id,
            'post_id' => $post->id,
        ]);
    }

    public function test_user_can_list_bookmarked_posts(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();

        $bookmarked = $this->createPublishedPost($author, $category, '付箋あり');
        $this->createPublishedPost($author, $category, '付箋なし');

        Bookmark::create([
            'user_id' => $viewer->id,
            'post_id' => $bookmarked->id,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/bookmarks')
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('posts.0.id', $bookmarked->id)
            ->assertJsonPath('posts.0.title', '付箋あり')
            ->assertJsonPath('posts.0.is_bookmarked', true)
            ->assertJsonPath('posts.0.bookmark_count', 1);
    }

    public function test_cannot_bookmark_draft_post(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();

        $post = Post::create([
            'user_id' => $author->id,
            'category_id' => $category->id,
            'title' => '下書き',
            'body' => '本文',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/posts/{$post->id}/bookmark")->assertNotFound();
    }

    public function test_post_list_uses_bookmark_count_from_bookmarks_table(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $other = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Bookmark::create(['user_id' => $viewer->id, 'post_id' => $post->id]);
        Bookmark::create(['user_id' => $other->id, 'post_id' => $post->id]);

        $post->update(['bookmark_count' => 0]);

        $this->getJson('/api/posts')
            ->assertOk()
            ->assertJsonPath('posts.0.bookmark_count', 2);
    }

    public function test_post_show_includes_is_bookmarked_for_authenticated_user(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Bookmark::create([
            'user_id' => $viewer->id,
            'post_id' => $post->id,
        ]);

        $token = $viewer->createToken('openpersona_token')->plainTextToken;

        $this->getJson("/api/posts/{$post->id}", [
            'Authorization' => "Bearer {$token}",
        ])
            ->assertOk()
            ->assertJsonPath('post.is_bookmarked', true)
            ->assertJsonPath('post.bookmark_count', 1);
    }
}
