<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\PostAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function createCategory(): Category
    {
        return Category::create([
            'name' => '政治',
            'slug' => 'politics',
            'sort_order' => 1,
        ]);
    }

    private function createDraftPost(User $user, Category $category): Post
    {
        return Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => '下書き',
            'body' => '本文',
            'status' => 'draft',
        ]);
    }

    public function test_guest_cannot_upload_attachments(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createDraftPost($user, $category);

        $this->postJson("/api/posts/{$post->id}/attachments", [
            'files' => [UploadedFile::fake()->image('photo.jpg')],
        ])->assertUnauthorized();
    }

    public function test_author_can_upload_multiple_attachments(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createDraftPost($user, $category);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/posts/{$post->id}/attachments", [
            'files' => [
                UploadedFile::fake()->image('photo.jpg')->size(1024),
                UploadedFile::fake()->create('document.pdf', 512, 'application/pdf'),
            ],
        ])->assertCreated()
            ->assertJsonCount(2, 'attachments')
            ->assertJsonPath('attachments.0.file_type', 'image')
            ->assertJsonPath('attachments.1.file_type', 'pdf');

        $this->assertDatabaseCount('post_attachments', 2);

        $storedPath = PostAttachment::query()->value('file_path');
        $this->assertNotNull($storedPath);
        Storage::disk('public')->assertExists($storedPath);
    }

    public function test_rejects_unsupported_file_type(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createDraftPost($user, $category);

        Sanctum::actingAs($user);

        $this->postJson("/api/posts/{$post->id}/attachments", [
            'files' => [UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream')],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0']);
    }

    public function test_author_can_delete_attachment(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createDraftPost($user, $category);

        $path = "attachments/{$post->id}/sample.jpg";
        Storage::disk('public')->put($path, 'image-data');

        $attachment = PostAttachment::create([
            'post_id' => $post->id,
            'file_name' => 'sample.jpg',
            'file_path' => $path,
            'file_type' => 'image',
            'file_size' => 100,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/posts/{$post->id}/attachments/{$attachment->id}")
            ->assertOk();

        $this->assertDatabaseMissing('post_attachments', ['id' => $attachment->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_post_show_includes_attachments(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => '公開投稿',
            'body' => '本文',
            'status' => 'published',
            'published_at' => now(),
        ]);

        PostAttachment::create([
            'post_id' => $post->id,
            'file_name' => 'chart.png',
            'file_path' => "attachments/{$post->id}/chart.png",
            'file_type' => 'image',
            'file_size' => 2048,
        ]);

        $this->getJson("/api/posts/{$post->id}")
            ->assertOk()
            ->assertJsonCount(1, 'post.attachments')
            ->assertJsonPath('post.attachments.0.file_name', 'chart.png')
            ->assertJsonPath('post.attachments.0.file_type', 'image');
    }
}
