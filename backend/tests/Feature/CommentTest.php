<?php

namespace Tests\Feature;

use App\Domain\Post\Models\Category;
use App\Domain\Post\Models\Comment;
use App\Domain\Post\Models\Post;
use App\Domain\Post\Services\CommentService;
use App\Models\IdentityVerification;
use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\TrustScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_comment_requires_body(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($commenter);

        $this->postJson("/api/posts/{$post->id}/comments", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    }

    public function test_comment_body_cannot_be_empty_string(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($commenter);

        $this->postJson("/api/posts/{$post->id}/comments", ['body' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    }

    public function test_comment_body_must_be_a_string(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($commenter);

        $this->postJson("/api/posts/{$post->id}/comments", ['body' => ['not', 'a', 'string']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    }

    public function test_comment_body_over_5000_characters_is_rejected(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($commenter);

        $this->postJson("/api/posts/{$post->id}/comments", ['body' => str_repeat('あ', 5001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    }

    public function test_comment_body_of_exactly_5000_characters_is_accepted(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($commenter);

        $body = str_repeat('あ', 5000);

        $this->postJson("/api/posts/{$post->id}/comments", ['body' => $body])
            ->assertCreated()
            ->assertJsonPath('comment.body', $body);
    }

    public function test_invalid_comment_body_is_not_saved(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($commenter);

        $this->postJson("/api/posts/{$post->id}/comments", ['body' => str_repeat('あ', 5001)])
            ->assertUnprocessable();

        $this->assertSame(0, Comment::query()->count());
    }

    public function test_comment_creation_ignores_spoofed_user_id_and_post_id(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $otherUser = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);
        $otherPost = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($commenter);

        $response = $this->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'コメントです。',
            'user_id' => $otherUser->id,
            'post_id' => $otherPost->id,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('comments', [
            'post_id' => $post->id,
            'user_id' => $commenter->id,
            'body' => 'コメントです。',
        ]);
        $this->assertDatabaseMissing('comments', [
            'post_id' => $otherPost->id,
            'user_id' => $otherUser->id,
        ]);
    }

    public function test_comment_service_prevents_creation_on_unpublished_post_when_called_directly(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory();

        $post = Post::create([
            'user_id' => $author->id,
            'category_id' => $category->id,
            'title' => '下書き',
            'body' => '非公開です。',
            'status' => 'draft',
        ]);

        $service = app(CommentService::class);

        $caught = null;

        try {
            $service->create($commenter, $post, 'コメントです。');
        } catch (HttpException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'サービスを直接呼び出した場合も、非公開投稿へのコメント作成は拒否されるはず。');
        $this->assertSame(404, $caught->getStatusCode());
        $this->assertSame(0, Comment::query()->count());
    }

    public function test_comment_response_hides_private_author_fields(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create(['first_name' => '非公開名']);
        Profile::create(['user_id' => $commenter->id]);
        ProfileVisibility::create([
            'user_id' => $commenter->id,
            'field_name' => 'first_name',
            'is_public' => false,
        ]);
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($commenter);

        $this->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'コメントです。',
        ])
            ->assertCreated()
            ->assertJsonPath('comment.user.first_name', null);
    }

    public function test_comment_response_shows_correct_max_score_when_trust_score_is_stale(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        IdentityVerification::create([
            'user_id' => $commenter->id,
            'verification_method' => 'driver_license',
            'verification_status' => IdentityVerification::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        // 本人確認前のTrustScore(max_score=未確認)が同期されずに
        // 残っている状態を再現する。
        TrustScore::where('user_id', $commenter->id)->update([
            'max_score' => TrustScore::MAX_SCORE_UNVERIFIED,
        ]);

        // リクエスト直前にDBの状態が意図どおりであることを確認する。
        $this->assertSame(
            TrustScore::MAX_SCORE_UNVERIFIED,
            (int) TrustScore::where('user_id', $commenter->id)->value('max_score'),
            '事前条件: DBのmax_scoreが50(未確認)になっているはず。'
        );

        Sanctum::actingAs($commenter);

        $this->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'コメントです。',
        ])
            ->assertCreated()
            ->assertJsonPath('comment.user.identity_verified', true)
            ->assertJsonPath('comment.user.trust_score.max_score', TrustScore::MAX_SCORE_VERIFIED);

        // summary整形のためにTrustScoreが補完・同期されていないこと
        // (一覧・投稿後表示ではDBを書き換えない方針)を確認する。
        $this->assertSame(
            TrustScore::MAX_SCORE_UNVERIFIED,
            (int) TrustScore::where('user_id', $commenter->id)->value('max_score'),
            'コメント投稿によってDBのmax_scoreが書き換えられてしまっています。'
        );
    }

    public function test_comment_author_max_score_is_correct_even_when_trust_score_is_missing(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        IdentityVerification::create([
            'user_id' => $commenter->id,
            'verification_method' => 'driver_license',
            'verification_status' => IdentityVerification::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        // TrustScoreの行自体が存在しない状態を再現する。
        TrustScore::where('user_id', $commenter->id)->delete();

        Comment::create([
            'post_id' => $post->id,
            'user_id' => $commenter->id,
            'body' => 'コメントです。',
        ]);

        $this->getJson("/api/posts/{$post->id}")
            ->assertOk()
            ->assertJsonPath('post.comments.0.user.identity_verified', true)
            ->assertJsonPath('post.comments.0.user.trust_score.max_score', TrustScore::MAX_SCORE_VERIFIED);
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

    public function test_cannot_comment_on_draft_post_with_invalid_body(): void
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

        // 本文が不正(空文字)でも、下書きへのコメントは422ではなく404になる。
        $this->postJson("/api/posts/{$post->id}/comments", [
            'body' => '',
        ])->assertNotFound();

        $this->assertSame(0, Comment::query()->count());
    }

    public function test_cannot_comment_on_deleted_post(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);
        $post->update(['status' => 'deleted']);

        Sanctum::actingAs($user);

        $this->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'コメントです。',
        ])->assertNotFound();

        $this->assertSame(0, Comment::query()->count());
    }

    public function test_cannot_comment_on_deleted_post_with_invalid_body(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);
        $post->update(['status' => 'deleted']);

        Sanctum::actingAs($user);

        // 本文が不正(空文字)でも、論理削除済み投稿へのコメントは
        // 422ではなく404になる。
        $this->postJson("/api/posts/{$post->id}/comments", [
            'body' => '',
        ])->assertNotFound();

        $this->assertSame(0, Comment::query()->count());
    }
}
