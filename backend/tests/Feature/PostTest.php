<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostTest extends TestCase
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

    private function createPublishedPost(User $user, Category $category): Post
    {
        return Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => '公開投稿',
            'body' => '本文です。',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function test_guest_can_list_published_posts(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $this->createPublishedPost($user, $category);

        $response = $this->getJson('/api/posts');

        $response->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('posts.0.title', '公開投稿');
    }

    public function test_guest_can_view_published_post(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);

        $response = $this->getJson("/api/posts/{$post->id}");

        $response->assertOk()
            ->assertJsonPath('post.title', '公開投稿')
            ->assertJsonPath('post.body', '本文です。');

        $this->assertSame(1, $post->fresh()->view_count);
    }

    public function test_show_increments_view_count_only_once_per_session(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);

        $this->getJson("/api/posts/{$post->id}")->assertOk();
        $this->getJson("/api/posts/{$post->id}")->assertOk();

        $this->assertSame(1, $post->fresh()->view_count);
    }

    public function test_show_increments_view_count_again_after_login(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);

        $this->getJson("/api/posts/{$post->id}")->assertOk();
        $this->assertSame(1, $post->fresh()->view_count);

        $loginResponse = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $token = $loginResponse->json('token');

        $this->getJson("/api/posts/{$post->id}", [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $this->assertSame(1, $post->fresh()->view_count);
    }

    public function test_show_does_not_increment_view_count_when_author_views_own_post(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);

        $token = $user->createToken('openpersona_token')->plainTextToken;

        $this->getJson("/api/posts/{$post->id}", [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $this->assertSame(0, $post->fresh()->view_count);
    }

    public function test_show_does_not_increment_view_count_after_creating_post(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $token = $user->createToken('openpersona_token')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        $createResponse = $this->postJson('/api/posts', [
            'category_id' => $category->id,
            'title' => '新規投稿',
            'body' => '本文',
            'status' => 'published',
        ], $headers)->assertCreated();

        $postId = $createResponse->json('post.id');

        $this->getJson("/api/posts/{$postId}", $headers)->assertOk();

        $this->assertSame(0, Post::find($postId)->view_count);
    }

    public function test_show_does_not_increment_view_count_twice_for_same_token(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        $token = $viewer->createToken('openpersona_token')->plainTextToken;

        $headers = ['Authorization' => "Bearer {$token}"];

        $this->getJson("/api/posts/{$post->id}", $headers)->assertOk();
        $this->getJson("/api/posts/{$post->id}", $headers)->assertOk();

        $this->assertSame(1, $post->fresh()->view_count);
    }

    public function test_guest_cannot_view_draft_post(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        $post = Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => '下書き',
            'body' => '非公開です。',
            'status' => 'draft',
        ]);

        $this->getJson("/api/posts/{$post->id}")->assertNotFound();
    }

    public function test_guest_cannot_create_post(): void
    {
        $category = $this->createCategory();

        $this->postJson('/api/posts', [
            'category_id' => $category->id,
            'title' => '新規投稿',
            'body' => '本文',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_post(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/posts', [
            'category_id' => $category->id,
            'title' => '新規投稿',
            'body' => '本文',
            'status' => 'published',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', '投稿を作成しました。')
            ->assertJsonPath('post.title', '新規投稿');

        $this->assertDatabaseHas('posts', [
            'user_id' => $user->id,
            'title' => '新規投稿',
            'status' => 'published',
        ]);
    }
}
