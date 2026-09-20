<?php

namespace Tests\Feature;

use App\Domain\Post\Models\Category;
use App\Domain\Post\Models\Post;
use App\Domain\Post\Models\PostAttachment;
use App\Domain\Post\Services\PostAttachmentService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_author_can_upload_single_attachment(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createDraftPost($user, $category);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/posts/{$post->id}/attachments", [
            'files' => [UploadedFile::fake()->image('photo.jpg')->size(1024)],
        ])->assertCreated()
            ->assertJsonCount(1, 'attachments')
            ->assertJsonPath('attachments.0.file_name', 'photo.jpg')
            ->assertJsonPath('attachments.0.file_type', 'image');

        $this->assertDatabaseCount('post_attachments', 1);

        $attachment = PostAttachment::query()->firstOrFail();
        $this->assertSame($post->id, $attachment->post_id);
        Storage::disk('public')->assertExists($attachment->file_path);
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

        // 返却順序(photo→document)がDB保存順・レスポンス順と一致し、
        // 各ファイル実体がそれぞれのDBレコードに正しく対応していることを確認する。
        $attachments = PostAttachment::query()->orderBy('id')->get();
        $this->assertSame('photo.jpg', $attachments[0]->file_name);
        $this->assertSame('document.pdf', $attachments[1]->file_name);
        $this->assertNotSame($attachments[0]->file_path, $attachments[1]->file_path);

        Storage::disk('public')->assertExists($attachments[0]->file_path);
        Storage::disk('public')->assertExists($attachments[1]->file_path);
    }

    public function test_upload_requires_at_least_one_file(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createDraftPost($user, $category);

        Sanctum::actingAs($user);

        $this->postJson("/api/posts/{$post->id}/attachments", [
            'files' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['files']);

        $this->assertDatabaseCount('post_attachments', 0);
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

    public function test_failed_validation_does_not_create_files_or_records(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createDraftPost($user, $category);

        Sanctum::actingAs($user);

        $this->postJson("/api/posts/{$post->id}/attachments", [
            'files' => [UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream')],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('post_attachments', 0);
        $this->assertEmpty(Storage::disk('public')->allFiles("attachments/{$post->id}"));
    }

    public function test_non_owner_cannot_upload_attachments(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createDraftPost($owner, $category);

        Sanctum::actingAs($other);

        $this->postJson("/api/posts/{$post->id}/attachments", [
            'files' => [UploadedFile::fake()->image('photo.jpg')],
        ])->assertForbidden();

        $this->assertDatabaseCount('post_attachments', 0);
        $this->assertEmpty(Storage::disk('public')->allFiles("attachments/{$post->id}"));
    }

    public function test_authorization_is_checked_before_validation_on_upload(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createDraftPost($owner, $category);

        Sanctum::actingAs($other);

        // 権限がなく、かつ入力(空配列)も不正なリクエストは、
        // 422ではなく403になる(認可が先)。
        $this->postJson("/api/posts/{$post->id}/attachments", [
            'files' => [],
        ])->assertForbidden();
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

    public function test_guest_cannot_delete_attachment(): void
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

        $this->deleteJson("/api/posts/{$post->id}/attachments/{$attachment->id}")
            ->assertUnauthorized();

        $this->assertDatabaseHas('post_attachments', ['id' => $attachment->id]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_non_owner_cannot_delete_attachment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createDraftPost($owner, $category);

        $path = "attachments/{$post->id}/sample.jpg";
        Storage::disk('public')->put($path, 'image-data');

        $attachment = PostAttachment::create([
            'post_id' => $post->id,
            'file_name' => 'sample.jpg',
            'file_path' => $path,
            'file_type' => 'image',
            'file_size' => 100,
        ]);

        Sanctum::actingAs($other);

        $this->deleteJson("/api/posts/{$post->id}/attachments/{$attachment->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('post_attachments', ['id' => $attachment->id]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_deleting_attachment_of_another_post_returns_not_found_and_keeps_file_and_record(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $postA = $this->createDraftPost($user, $category);
        $postB = $this->createDraftPost($user, $category);

        $path = "attachments/{$postA->id}/sample.jpg";
        Storage::disk('public')->put($path, 'image-data');

        $attachment = PostAttachment::create([
            'post_id' => $postA->id,
            'file_name' => 'sample.jpg',
            'file_path' => $path,
            'file_type' => 'image',
            'file_size' => 100,
        ]);

        Sanctum::actingAs($user);

        // 認可(attach)は自分の投稿Bに対しては通るが、添付は投稿Aに
        // 属するため404になる。
        $this->deleteJson("/api/posts/{$postB->id}/attachments/{$attachment->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('post_attachments', ['id' => $attachment->id]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_post_attachment_service_destroy_enforces_post_ownership_directly(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $postA = $this->createDraftPost($user, $category);
        $postB = $this->createDraftPost($user, $category);

        $path = "attachments/{$postA->id}/sample.jpg";
        Storage::disk('public')->put($path, 'image-data');

        $attachment = PostAttachment::create([
            'post_id' => $postA->id,
            'file_name' => 'sample.jpg',
            'file_path' => $path,
            'file_type' => 'image',
            'file_size' => 100,
        ]);

        $service = app(PostAttachmentService::class);

        $caught = null;

        try {
            $service->destroy($postB, $attachment);
        } catch (HttpException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'サービスを直接呼び出した場合も、別投稿の添付削除は拒否されるはず。');
        $this->assertSame(404, $caught->getStatusCode());
        $this->assertDatabaseHas('post_attachments', ['id' => $attachment->id]);
        Storage::disk('public')->assertExists($path);
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
