<?php

namespace Tests\Feature;

use App\Domain\Post\Exceptions\PostAttachmentOperationFailedException;
use App\Domain\Post\Models\Category;
use App\Domain\Post\Models\Post;
use App\Domain\Post\Models\PostAttachment;
use App\Domain\Post\Services\PostAttachmentService;
use App\Models\User;
use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PostAttachmentFailureHandlingTest extends TestCase
{
    use RefreshDatabase;

    /** 許可するログcontextのキー(トークン・ファイル内容などは含めない)。 */
    private const ALLOWED_CONTEXT_KEYS = ['operation', 'post_id', 'attachment_id', 'file_name', 'file_path'];

    private FilesystemAdapter $realDisk;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->realDisk = Storage::disk('public');
    }

    private function createCategory(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'politics'],
            ['name' => '政治', 'sort_order' => 1],
        );
    }

    private function createDraftPost(?User $user = null): Post
    {
        $user ??= User::factory()->create();

        return Post::create([
            'user_id' => $user->id,
            'category_id' => $this->createCategory()->id,
            'title' => '下書き',
            'body' => '本文',
            'status' => 'draft',
        ]);
    }

    private function createStoredAttachment(Post $post, string $name = 'sample.jpg', string $content = 'image-data'): PostAttachment
    {
        $path = "attachments/{$post->id}/{$name}";
        $this->realDisk->put($path, $content);

        return PostAttachment::create([
            'post_id' => $post->id,
            'file_name' => $name,
            'file_path' => $path,
            'file_type' => 'image',
            'file_size' => 100,
        ]);
    }

    /**
     * 実体はStorage::fake('public')の本物のフェイクディスクに委譲しつつ、
     * putFileAs/deleteなどをテストごとに差し替えられるようにする。
     * UploadedFile::store()・Storage::disk('public')は同一のFilesystemManager
     * を経由するため、Storage::set()での差し替えは両方に反映される。
     */
    private function mockPublicDisk(): MockInterface
    {
        $mock = Mockery::mock($this->realDisk)->makePartial();
        Storage::set('public', $mock);

        return $mock;
    }

    /**
     * $fnを実行し、PostAttachmentOperationFailedExceptionを返す。
     * 例外が発生しない・別の種類の例外が発生した場合はテストを失敗させる
     * (無関係な例外を握りつぶさない)。
     */
    private function expectOperationFailure(Closure $fn): PostAttachmentOperationFailedException
    {
        try {
            $fn();
        } catch (PostAttachmentOperationFailedException $e) {
            return $e;
        }

        $this->fail('PostAttachmentOperationFailedExceptionが発生しませんでした。');
    }

    /** 例外のcontextが期待どおりで、許可外のキー(機密情報など)を含まないこと。 */
    private function assertContext(PostAttachmentOperationFailedException $e, array $expected): void
    {
        $context = $e->context();

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $context, "contextに{$key}がありません。");
            $this->assertSame($value, $context[$key], "contextの{$key}が想定と異なります。");
        }

        $this->assertSame([], array_diff(array_keys($context), self::ALLOWED_CONTEXT_KEYS));
    }

    // ------------------------------------------------------------------
    // store(): 最初のファイル保存が失敗
    // ------------------------------------------------------------------

    public function test_store_does_not_create_record_when_first_file_storage_returns_false(): void
    {
        $post = $this->createDraftPost();

        $mock = $this->mockPublicDisk();
        $mock->shouldReceive('putFileAs')->once()->andReturn(false);

        $e = $this->expectOperationFailure(
            fn () => app(PostAttachmentService::class)->store($post, [UploadedFile::fake()->image('photo.jpg')])
        );

        $this->assertNull($e->getPrevious());
        $this->assertContext($e, ['operation' => 'store_file', 'post_id' => $post->id, 'file_name' => 'photo.jpg']);
        $this->assertSame(0, PostAttachment::query()->count());
    }

    public function test_store_does_not_create_record_when_first_file_storage_throws(): void
    {
        $post = $this->createDraftPost();
        $injected = new RuntimeException('disk unavailable');

        $mock = $this->mockPublicDisk();
        $mock->shouldReceive('putFileAs')->once()->andThrow($injected);

        $e = $this->expectOperationFailure(
            fn () => app(PostAttachmentService::class)->store($post, [UploadedFile::fake()->image('photo.jpg')])
        );

        $this->assertSame($injected, $e->getPrevious(), '注入した元の例外が保持されていません。');
        $this->assertContext($e, ['operation' => 'store_file', 'post_id' => $post->id]);
        $this->assertSame(0, PostAttachment::query()->count());
    }

    // ------------------------------------------------------------------
    // store(): 複数ファイルの2件目の保存が失敗(1件目は保存成功済み)
    // ------------------------------------------------------------------

    /**
     * 1件目のputFileAsは実際に成功させ、2件目で$onSecondを実行する。
     *
     * @return array{0: MockInterface, 1: \stdClass} $state->firstPath=1件目の保存先
     */
    private function mockSecondStorageFailure(Closure $onSecond): array
    {
        $state = new \stdClass;
        $state->firstPath = null;
        $state->calls = 0;

        $real = $this->realDisk;
        $mock = $this->mockPublicDisk();
        $mock->shouldReceive('putFileAs')->twice()->andReturnUsing(function (...$args) use ($state, $real, $onSecond) {
            $state->calls++;

            if ($state->calls === 2) {
                return $onSecond();
            }

            return $state->firstPath = $real->putFileAs(...$args);
        });

        return [$mock, $state];
    }

    public function test_store_cleans_up_first_file_when_second_file_storage_returns_false(): void
    {
        $post = $this->createDraftPost();
        [, $state] = $this->mockSecondStorageFailure(fn () => false);

        $e = $this->expectOperationFailure(fn () => app(PostAttachmentService::class)->store($post, [
            UploadedFile::fake()->createWithContent('first.pdf', 'CONTENT-FIRST'),
            UploadedFile::fake()->createWithContent('second.pdf', 'CONTENT-SECOND'),
        ]));

        $this->assertSame(2, $state->calls, '2件目の保存まで到達していません。');
        $this->assertNotNull($state->firstPath, '1件目は実際に保存されているはず。');
        $this->assertContext($e, ['operation' => 'store_file', 'post_id' => $post->id, 'file_name' => 'second.pdf']);

        $this->assertSame(0, PostAttachment::query()->count());
        Storage::disk('public')->assertMissing($state->firstPath);
        $this->assertSame([], Storage::disk('public')->allFiles("attachments/{$post->id}"));
    }

    public function test_store_cleans_up_first_file_when_second_file_storage_throws(): void
    {
        $post = $this->createDraftPost();
        $injected = new RuntimeException('disk full on second file');
        [, $state] = $this->mockSecondStorageFailure(fn () => throw $injected);

        $e = $this->expectOperationFailure(fn () => app(PostAttachmentService::class)->store($post, [
            UploadedFile::fake()->createWithContent('first.pdf', 'CONTENT-FIRST'),
            UploadedFile::fake()->createWithContent('second.pdf', 'CONTENT-SECOND'),
        ]));

        $this->assertSame(2, $state->calls, '2件目の保存まで到達していません。');
        $this->assertNotNull($state->firstPath, '1件目は実際に保存されているはず。');
        $this->assertSame($injected, $e->getPrevious(), '注入した元の例外が保持されていません。');
        $this->assertContext($e, ['operation' => 'store_file', 'post_id' => $post->id]);

        $this->assertSame(0, PostAttachment::query()->count());
        Storage::disk('public')->assertMissing($state->firstPath);
        $this->assertSame([], Storage::disk('public')->allFiles("attachments/{$post->id}"));
    }

    // ------------------------------------------------------------------
    // store(): DB登録の失敗
    // ------------------------------------------------------------------

    public function test_store_cleans_up_file_when_db_registration_fails_after_successful_storage(): void
    {
        $post = $this->createDraftPost();
        $injected = new RuntimeException('db insert failed');

        $creatingCalls = 0;
        PostAttachment::creating(function () use (&$creatingCalls, $injected) {
            $creatingCalls++;

            throw $injected;
        });

        $e = $this->expectOperationFailure(
            fn () => app(PostAttachmentService::class)->store($post, [UploadedFile::fake()->image('photo.jpg')])
        );

        $this->assertSame(1, $creatingCalls, 'DB登録(creating)まで到達していません。');
        $this->assertSame($injected, $e->getPrevious(), '注入した元の例外が保持されていません。');
        $this->assertContext($e, ['operation' => 'register_attachment', 'post_id' => $post->id]);
        $this->assertSame(0, PostAttachment::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles("attachments/{$post->id}"));
    }

    public function test_store_rolls_back_registrations_and_cleans_up_files_when_a_later_file_fails_to_register(): void
    {
        $post = $this->createDraftPost();
        $injected = new RuntimeException('db insert failed on second file');

        $creatingCalls = 0;
        $countBeforeSecond = null;
        PostAttachment::creating(function () use (&$creatingCalls, &$countBeforeSecond, $injected) {
            $creatingCalls++;

            if ($creatingCalls === 2) {
                // 失敗直前に、1件目がトランザクション内で登録済みであること。
                $countBeforeSecond = PostAttachment::query()->count();

                throw $injected;
            }
        });

        $e = $this->expectOperationFailure(fn () => app(PostAttachmentService::class)->store($post, [
            UploadedFile::fake()->image('photo1.jpg'),
            UploadedFile::fake()->image('photo2.jpg'),
        ]));

        $this->assertSame(2, $creatingCalls);
        $this->assertSame(1, $countBeforeSecond, '2件目の失敗直前に1件目が登録済みであるはず。');
        $this->assertSame($injected, $e->getPrevious(), '注入した元の例外が保持されていません。');
        $this->assertContext($e, ['operation' => 'register_attachment', 'post_id' => $post->id]);

        $this->assertSame(0, PostAttachment::query()->count(), '1件目のDB登録がロールバックされていません。');
        $this->assertSame([], Storage::disk('public')->allFiles("attachments/{$post->id}"));
    }

    // ------------------------------------------------------------------
    // store(): 後片付け自体の失敗(false / 例外)
    // ------------------------------------------------------------------

    /**
     * 2件のファイルを保存後、2件目のDB登録で失敗させ、後片付け(delete)の
     * 1回目だけを$firstCleanupで失敗させる。2回目以降は実際に削除する。
     *
     * @return array{0: \stdClass, 1: RuntimeException, 2: PostAttachmentOperationFailedException}
     */
    private function runStoreWithFailingFirstCleanup(Post $post, Closure $firstCleanup): array
    {
        $dbException = new RuntimeException('db insert failed on second file');
        $creatingCalls = 0;
        PostAttachment::creating(function () use (&$creatingCalls, $dbException) {
            $creatingCalls++;

            if ($creatingCalls === 2) {
                throw $dbException;
            }
        });

        $state = new \stdClass;
        $state->deleteCalls = [];

        $real = $this->realDisk;
        $mock = $this->mockPublicDisk();
        $mock->shouldReceive('delete')->twice()->andReturnUsing(function ($path) use ($state, $real, $firstCleanup) {
            $state->deleteCalls[] = $path;

            if (count($state->deleteCalls) === 1) {
                return $firstCleanup($path);
            }

            return $real->delete($path);
        });

        $e = $this->expectOperationFailure(fn () => app(PostAttachmentService::class)->store($post, [
            UploadedFile::fake()->createWithContent('first.pdf', 'CONTENT-FIRST'),
            UploadedFile::fake()->createWithContent('second.pdf', 'CONTENT-SECOND'),
        ]));

        $this->assertSame(2, $creatingCalls, '2件目のDB登録まで到達していません。');

        return [$state, $dbException, $e];
    }

    public function test_store_continues_cleanup_when_first_cleanup_returns_false(): void
    {
        Exceptions::fake();
        $post = $this->createDraftPost();

        [$state, $dbException, $e] = $this->runStoreWithFailingFirstCleanup($post, fn () => false);

        [$failedPath, $deletedPath] = $state->deleteCalls;
        $this->assertNotSame($failedPath, $deletedPath);

        // 元のエラー(DB登録失敗)が保持されている。
        $this->assertSame('添付ファイルの登録に失敗しました。', $e->getMessage());
        $this->assertSame($dbException, $e->getPrevious());
        $this->assertContext($e, ['operation' => 'register_attachment', 'post_id' => $post->id]);

        // 失敗した対象は残り、成功した対象は実際に消えている。DB登録はロールバック済み。
        Storage::disk('public')->assertExists($failedPath);
        Storage::disk('public')->assertMissing($deletedPath);
        $this->assertSame([$failedPath], Storage::disk('public')->allFiles("attachments/{$post->id}"));
        $this->assertSame(0, PostAttachment::query()->count());

        // 後片付け失敗が、operation・post_id・file_path付きで報告されている。
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(function (PostAttachmentOperationFailedException $reported) use ($post, $failedPath) {
            return $reported->getPrevious() === null
                && ($reported->context()['operation'] ?? null) === 'cleanup_file'
                && ($reported->context()['post_id'] ?? null) === $post->id
                && ($reported->context()['file_path'] ?? null) === $failedPath;
        });
    }

    public function test_store_continues_cleanup_when_first_cleanup_throws(): void
    {
        Exceptions::fake();
        $post = $this->createDraftPost();
        $cleanupException = new RuntimeException('permission denied on cleanup');

        [$state, $dbException, $e] = $this->runStoreWithFailingFirstCleanup(
            $post,
            fn () => throw $cleanupException
        );

        [$failedPath, $deletedPath] = $state->deleteCalls;
        $this->assertNotSame($failedPath, $deletedPath);

        // 元のエラー(DB登録失敗)が、後片付けの例外に差し替えられず保持されている。
        $this->assertSame('添付ファイルの登録に失敗しました。', $e->getMessage());
        $this->assertSame($dbException, $e->getPrevious());

        Storage::disk('public')->assertExists($failedPath);
        Storage::disk('public')->assertMissing($deletedPath);
        $this->assertSame([$failedPath], Storage::disk('public')->allFiles("attachments/{$post->id}"));
        $this->assertSame(0, PostAttachment::query()->count());

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(function (PostAttachmentOperationFailedException $reported) use ($post, $failedPath, $cleanupException) {
            return $reported->getPrevious() === $cleanupException
                && ($reported->context()['operation'] ?? null) === 'cleanup_file'
                && ($reported->context()['post_id'] ?? null) === $post->id
                && ($reported->context()['file_path'] ?? null) === $failedPath;
        });
    }

    // ------------------------------------------------------------------
    // store(): 既存データ・他投稿への非干渉(後片付けが実際に走る状況)
    // ------------------------------------------------------------------

    public function test_store_cleanup_only_removes_files_saved_by_this_call(): void
    {
        $post = $this->createDraftPost();
        $otherPost = $this->createDraftPost();

        $existingSame = $this->createStoredAttachment($post, 'existing-same.jpg', 'SAME-POST');
        $existingOther = $this->createStoredAttachment($otherPost, 'existing-other.jpg', 'OTHER-POST');

        // 既存データ作成後に、新規登録だけを失敗させる。
        $injected = new RuntimeException('db insert failed');
        $creatingCalls = 0;
        PostAttachment::creating(function () use (&$creatingCalls, $injected) {
            $creatingCalls++;

            throw $injected;
        });

        $deleted = [];
        $real = $this->realDisk;
        $mock = $this->mockPublicDisk();
        $mock->shouldReceive('delete')->once()->andReturnUsing(function ($path) use (&$deleted, $real) {
            $deleted[] = $path;

            return $real->delete($path);
        });

        $this->expectOperationFailure(fn () => app(PostAttachmentService::class)->store($post, [
            UploadedFile::fake()->createWithContent('new.pdf', 'NEW-CONTENT'),
        ]));

        $this->assertSame(1, $creatingCalls, 'DB登録まで到達していません(後片付けが走る状況ではありません)。');

        // 後片付けで削除されたのは、今回保存した(既存ではない)ファイルだけ。
        $this->assertCount(1, $deleted);
        $this->assertStringStartsWith("attachments/{$post->id}/", $deleted[0]);
        $this->assertNotSame($existingSame->file_path, $deleted[0]);
        Storage::disk('public')->assertMissing($deleted[0]);

        // 同じ投稿・別投稿の既存ファイルとDBレコードは維持される。
        $this->assertDatabaseHas('post_attachments', ['id' => $existingSame->id]);
        $this->assertDatabaseHas('post_attachments', ['id' => $existingOther->id]);
        $this->assertSame('SAME-POST', Storage::disk('public')->get($existingSame->file_path));
        $this->assertSame('OTHER-POST', Storage::disk('public')->get($existingOther->file_path));
        $this->assertSame(2, PostAttachment::query()->count());
    }

    // ------------------------------------------------------------------
    // store(): HTTP経由での失敗応答
    // ------------------------------------------------------------------

    public function test_store_returns_server_error_without_leaking_details_on_failure(): void
    {
        $user = User::factory()->create();
        $post = $this->createDraftPost($user);

        $mock = $this->mockPublicDisk();
        $mock->shouldReceive('putFileAs')->once()->andThrow(new RuntimeException('SELECT * FROM secrets; /var/www/app/storage'));

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/posts/{$post->id}/attachments", [
            'files' => [UploadedFile::fake()->image('photo.jpg')],
        ]);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('secrets', $response->getContent());
        $this->assertStringNotContainsString('/var/www', $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
        $this->assertStringNotContainsString('attachments/', $response->getContent());

        $this->assertSame(0, PostAttachment::query()->count());
    }

    // ------------------------------------------------------------------
    // destroy(): ファイル削除が失敗
    // ------------------------------------------------------------------

    /** DBのdeleting(削除試行)イベントの呼び出し回数を数えるリスナーを登録する。 */
    private function countDeletingEvents(): \stdClass
    {
        $counter = new \stdClass;
        $counter->calls = 0;
        PostAttachment::deleting(function () use ($counter) {
            $counter->calls++;
        });

        return $counter;
    }

    public function test_destroy_keeps_record_when_file_deletion_returns_false(): void
    {
        $post = $this->createDraftPost();
        $attachment = $this->createStoredAttachment($post);
        $deletingEvents = $this->countDeletingEvents();

        $mock = $this->mockPublicDisk();
        $mock->shouldReceive('delete')->once()->with($attachment->file_path)->andReturn(false);

        $e = $this->expectOperationFailure(fn () => app(PostAttachmentService::class)->destroy($post, $attachment));

        $this->assertNull($e->getPrevious());
        $this->assertContext($e, ['operation' => 'delete_file', 'post_id' => $post->id, 'attachment_id' => $attachment->id]);
        $this->assertSame(0, $deletingEvents->calls, 'ファイル削除失敗後にDB削除が試行されています。');
        $this->assertDatabaseHas('post_attachments', ['id' => $attachment->id]);
    }

    public function test_destroy_keeps_record_when_file_deletion_throws(): void
    {
        $post = $this->createDraftPost();
        $attachment = $this->createStoredAttachment($post);
        $deletingEvents = $this->countDeletingEvents();
        $injected = new RuntimeException('permission denied');

        $mock = $this->mockPublicDisk();
        $mock->shouldReceive('delete')->once()->with($attachment->file_path)->andThrow($injected);

        $e = $this->expectOperationFailure(fn () => app(PostAttachmentService::class)->destroy($post, $attachment));

        $this->assertSame($injected, $e->getPrevious(), '注入した元の例外が保持されていません。');
        $this->assertContext($e, ['operation' => 'delete_file', 'post_id' => $post->id, 'attachment_id' => $attachment->id]);
        $this->assertSame(0, $deletingEvents->calls, 'ファイル削除失敗後にDB削除が試行されています。');
        $this->assertDatabaseHas('post_attachments', ['id' => $attachment->id]);
    }

    public function test_destroy_returns_server_error_without_leaking_details_via_http(): void
    {
        $user = User::factory()->create();
        $post = $this->createDraftPost($user);
        $attachment = $this->createStoredAttachment($post);

        $mock = $this->mockPublicDisk();
        $mock->shouldReceive('delete')->once()->andThrow(new RuntimeException('SELECT * FROM secrets'));

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/posts/{$post->id}/attachments/{$attachment->id}");

        $response->assertStatus(500);
        $this->assertStringNotContainsString('secrets', $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
        $this->assertStringNotContainsString('attachments/', $response->getContent());

        $this->assertDatabaseHas('post_attachments', ['id' => $attachment->id]);
    }

    // ------------------------------------------------------------------
    // destroy(): ファイルが既に存在しない場合
    // ------------------------------------------------------------------

    public function test_destroy_removes_record_when_file_is_already_missing(): void
    {
        $post = $this->createDraftPost();

        // ファイル実体を作らないまま、DBレコードだけ存在する状況を再現する。
        $path = "attachments/{$post->id}/already-gone.jpg";
        Storage::disk('public')->assertMissing($path);

        $attachment = PostAttachment::create([
            'post_id' => $post->id,
            'file_name' => 'already-gone.jpg',
            'file_path' => $path,
            'file_type' => 'image',
            'file_size' => 100,
        ]);

        app(PostAttachmentService::class)->destroy($post, $attachment);

        $this->assertDatabaseMissing('post_attachments', ['id' => $attachment->id]);
    }

    // ------------------------------------------------------------------
    // destroy(): ファイル削除後のDB削除失敗、およびその後の再試行
    // ------------------------------------------------------------------

    public function test_destroy_does_not_succeed_when_db_deletion_fails_after_file_deletion(): void
    {
        $post = $this->createDraftPost();
        $attachment = $this->createStoredAttachment($post);
        $injected = new RuntimeException('db delete failed');

        $deletingCalls = 0;
        PostAttachment::deleting(function () use (&$deletingCalls, $injected) {
            $deletingCalls++;

            throw $injected;
        });

        $e = $this->expectOperationFailure(fn () => app(PostAttachmentService::class)->destroy($post, $attachment));

        $this->assertSame(1, $deletingCalls, 'DB削除(deleting)まで到達していません。');
        $this->assertSame($injected, $e->getPrevious(), '注入した元の例外が保持されていません。');
        $this->assertContext($e, ['operation' => 'delete_record', 'post_id' => $post->id, 'attachment_id' => $attachment->id]);

        // ファイルは既に削除済みだが、DBレコードは残る(復元不可の制約)。
        Storage::disk('public')->assertMissing($attachment->file_path);
        $this->assertDatabaseHas('post_attachments', ['id' => $attachment->id]);
    }

    public function test_destroy_can_be_retried_successfully_after_db_deletion_failure_is_resolved(): void
    {
        $post = $this->createDraftPost();
        $attachment = $this->createStoredAttachment($post);

        $shouldFail = true;
        $deletingCalls = 0;
        PostAttachment::deleting(function () use (&$shouldFail, &$deletingCalls) {
            $deletingCalls++;

            if ($shouldFail) {
                throw new RuntimeException('db delete failed');
            }
        });

        $service = app(PostAttachmentService::class);

        $this->expectOperationFailure(fn () => $service->destroy($post, $attachment));

        $this->assertSame(1, $deletingCalls, '1回目はDB削除まで到達して失敗するはず。');
        $this->assertDatabaseHas('post_attachments', ['id' => $attachment->id]);
        Storage::disk('public')->assertMissing($attachment->file_path);

        // DB側の障害が解消したことを模擬し、同じ添付に対して再試行する。
        // ファイルは既に無いためファイル削除はno-op成功となり、DB削除だけが行われる。
        $shouldFail = false;

        $service->destroy($post, $attachment->fresh());

        $this->assertSame(2, $deletingCalls);
        $this->assertDatabaseMissing('post_attachments', ['id' => $attachment->id]);
    }
}
