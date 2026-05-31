<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommentTest extends TestCase
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

    public function test_guest_cannot_create_comment(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);

        $this->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'コメントです。',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_comment(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($commenter);

        $response = $this->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'コメントです。',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'コメントを投稿しました。')
            ->assertJsonPath('comment.body', 'コメントです。');

        $this->assertDatabaseHas('comments', [
            'post_id' => $post->id,
            'user_id' => $commenter->id,
            'body' => 'コメントです。',
        ]);
    }

    public function test_guest_can_view_comments_on_post_detail(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Comment::create([
            'post_id' => $post->id,
            'user_id' => $commenter->id,
            'body' => '最初のコメント',
            'created_at' => now()->subMinute(),
        ]);

        Comment::create([
            'post_id' => $post->id,
            'user_id' => $author->id,
            'body' => '二番目のコメント',
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/posts/{$post->id}");

        $response->assertOk()
            ->assertJsonPath('post.comments.0.body', '最初のコメント')
            ->assertJsonPath('post.comments.1.body', '二番目のコメント');
    }

    public function test_cannot_comment_on_draft_post(): void
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

        Sanctum::actingAs($user);

        $this->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'コメントです。',
        ])->assertNotFound();
    }
}
