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
