<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\IdentityVerification;
use App\Models\Post;
use App\Models\Profile;
use App\Models\TrustScore;
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
        $user = User::factory()->create(['birthdate' => '1990-01-01']);
        Profile::create([
            'user_id' => $user->id,
            'region' => '東京都',
        ]);
        $category = $this->createCategory();
        $this->createPublishedPost($user, $category);

        $response = $this->getJson('/api/posts');

        $response->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('posts.0.title', '公開投稿')
            ->assertJsonPath('posts.0.user.region', '東京都')
            ->assertJsonPath('posts.0.user.age', $user->birthdate->age)
            ->assertJsonPath('posts.0.user.trust_score.max_score', TrustScore::MAX_SCORE_UNVERIFIED)
            ->assertJsonPath('posts.0.user.identity_verified', false);
    }

    public function test_post_list_shows_verified_author_badge(): void
    {
        $user = User::factory()->create(['birthdate' => '1990-01-01']);
        Profile::create(['user_id' => $user->id, 'region' => '東京都']);

        IdentityVerification::create([
            'user_id' => $user->id,
            'verification_method' => 'driver_license',
            'verification_status' => IdentityVerification::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        TrustScore::ensureForUser($user);
        $category = $this->createCategory();
        $this->createPublishedPost($user, $category);

        $this->getJson('/api/posts')
            ->assertOk()
            ->assertJsonPath('posts.0.user.identity_verified', true)
            ->assertJsonPath('posts.0.user.trust_score.max_score', TrustScore::MAX_SCORE_VERIFIED);
    }

    public function test_guest_can_view_published_post(): void
    {
        $user = User::factory()->create(['birthdate' => '1990-01-01']);
        Profile::create([
            'user_id' => $user->id,
            'region' => '東京都',
        ]);
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);

        $response = $this->getJson("/api/posts/{$post->id}");

        $response->assertOk()
            ->assertJsonPath('post.title', '公開投稿')
            ->assertJsonPath('post.body', '本文です。')
            ->assertJsonPath('post.user.last_name', $user->last_name)
            ->assertJsonPath('post.user.first_name', null)
            ->assertJsonPath('post.user.region', '東京都')
            ->assertJsonPath('post.user.age', $user->birthdate->age);

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
            ->assertJsonPath('message', '投稿を公開しました。')
            ->assertJsonPath('post.title', '新規投稿');

        $this->assertDatabaseHas('posts', [
            'user_id' => $user->id,
            'title' => '新規投稿',
            'status' => 'published',
        ]);
    }

    public function test_authenticated_user_can_create_post_with_sources(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/posts', [
            'category_id' => $category->id,
            'title' => '参考文献付き投稿',
            'body' => '本文',
            'status' => 'published',
            'sources' => [
                [
                    'source_type' => 'url',
                    'title' => '参考記事',
                    'url' => 'https://example.com/reference',
                    'note' => '一次情報',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('post.title', '参考文献付き投稿')
            ->assertJsonCount(1, 'post.sources')
            ->assertJsonPath('post.sources.0.source_type', 'url')
            ->assertJsonPath('post.sources.0.title', '参考記事')
            ->assertJsonPath('post.sources.0.url', 'https://example.com/reference')
            ->assertJsonPath('post.sources.0.note', '一次情報');

        $postId = $response->json('post.id');

        $this->assertDatabaseHas('post_sources', [
            'post_id' => $postId,
            'source_type' => 'url',
            'title' => '参考記事',
            'url' => 'https://example.com/reference',
        ]);
    }

    public function test_show_includes_post_sources(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);

        $post->sources()->create([
            'source_type' => 'book',
            'title' => '参考書籍',
            'note' => '第3章',
        ]);

        $this->getJson("/api/posts/{$post->id}")
            ->assertOk()
            ->assertJsonCount(1, 'post.sources')
            ->assertJsonPath('post.sources.0.source_type', 'book')
            ->assertJsonPath('post.sources.0.title', '参考書籍')
            ->assertJsonPath('post.sources.0.note', '第3章');
    }

    public function test_post_source_url_must_use_http_or_https(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        Sanctum::actingAs($user);

        $this->postJson('/api/posts', [
            'category_id' => $category->id,
            'title' => '危険なURL',
            'body' => '本文',
            'status' => 'draft',
            'sources' => [
                [
                    'source_type' => 'url',
                    'url' => 'javascript:alert(1)',
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['sources.0.url']);

        $this->postJson('/api/posts', [
            'category_id' => $category->id,
            'title' => '有効なURL',
            'body' => '本文',
            'status' => 'draft',
            'sources' => [
                [
                    'source_type' => 'url',
                    'url' => 'https://example.com/safe',
                ],
            ],
        ])->assertCreated();
    }

    public function test_author_can_replace_sources_on_update(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        $post = Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => '下書き',
            'body' => '本文',
            'status' => 'draft',
        ]);

        $post->sources()->create([
            'source_type' => 'url',
            'url' => 'https://example.com/old',
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/posts/{$post->id}", [
            'sources' => [
                [
                    'source_type' => 'paper',
                    'title' => '論文タイトル',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'post.sources')
            ->assertJsonPath('post.sources.0.source_type', 'paper')
            ->assertJsonPath('post.sources.0.title', '論文タイトル');

        $this->assertDatabaseMissing('post_sources', [
            'post_id' => $post->id,
            'url' => 'https://example.com/old',
        ]);

        $this->assertDatabaseHas('post_sources', [
            'post_id' => $post->id,
            'source_type' => 'paper',
            'title' => '論文タイトル',
        ]);
    }

    public function test_authenticated_user_can_create_draft(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/posts', [
            'category_id' => $category->id,
            'title' => '下書き投稿',
            'body' => '本文',
            'status' => 'draft',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', '下書きを保存しました。')
            ->assertJsonPath('post.status', 'draft');

        $this->assertDatabaseHas('posts', [
            'user_id' => $user->id,
            'title' => '下書き投稿',
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    public function test_authenticated_user_can_list_own_drafts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $category = $this->createCategory();

        Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => '自分の下書き',
            'body' => '本文',
            'status' => 'draft',
        ]);

        Post::create([
            'user_id' => $otherUser->id,
            'category_id' => $category->id,
            'title' => '他人の下書き',
            'body' => '本文',
            'status' => 'draft',
        ]);

        $this->createPublishedPost($user, $category);

        Sanctum::actingAs($user);

        $this->getJson('/api/posts/drafts')
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('posts.0.title', '自分の下書き');
    }

    public function test_guest_cannot_list_drafts(): void
    {
        $this->getJson('/api/posts/drafts')->assertUnauthorized();
    }

    public function test_author_can_view_own_draft(): void
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

        $token = $user->createToken('openpersona_token')->plainTextToken;

        $this->getJson("/api/posts/{$post->id}", [
            'Authorization' => "Bearer {$token}",
        ])
            ->assertOk()
            ->assertJsonPath('post.title', '下書き')
            ->assertJsonPath('post.body', '非公開です。');

        $this->assertSame(0, $post->fresh()->view_count);
    }

    public function test_author_cannot_update_published_post(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);

        Sanctum::actingAs($user);

        $this->putJson("/api/posts/{$post->id}", [
            'title' => '改ざんタイトル',
        ])->assertForbidden();
    }

    public function test_author_can_copy_published_post_as_correction_draft(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);
        $post->sources()->create([
            'source_type' => 'url',
            'url' => 'https://example.com/source',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/posts/{$post->id}/copy")
            ->assertCreated()
            ->assertJsonPath('copied_from_post_id', $post->id)
            ->assertJsonPath('post.status', 'draft')
            ->assertJsonPath('post.title', '【訂正】公開投稿')
            ->assertJsonPath('post.body', '本文です。')
            ->assertJsonCount(1, 'post.sources');

        $copyId = $response->json('post.id');

        $this->assertDatabaseHas('posts', [
            'id' => $copyId,
            'user_id' => $user->id,
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->assertDatabaseHas('post_sources', [
            'post_id' => $copyId,
            'url' => 'https://example.com/source',
        ]);
    }

    public function test_user_cannot_copy_other_users_post(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($other);

        $this->postJson("/api/posts/{$post->id}/copy")->assertForbidden();
    }

    public function test_author_can_update_draft_and_publish(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        $post = Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => '下書き',
            'body' => '旧本文',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/posts/{$post->id}", [
            'title' => '更新タイトル',
            'body' => '新本文',
            'status' => 'published',
        ])
            ->assertOk()
            ->assertJsonPath('message', '投稿を公開しました。')
            ->assertJsonPath('post.title', '更新タイトル')
            ->assertJsonPath('post.status', 'published');

        $post->refresh();

        $this->assertSame('新本文', $post->body);
        $this->assertNotNull($post->published_at);
    }

    public function test_user_cannot_update_other_users_post(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $category = $this->createCategory();

        $post = Post::create([
            'user_id' => $author->id,
            'category_id' => $category->id,
            'title' => '下書き',
            'body' => '本文',
            'status' => 'draft',
        ]);

        Sanctum::actingAs($other);

        $this->putJson("/api/posts/{$post->id}", [
            'title' => '改ざん',
        ])->assertForbidden();
    }

    public function test_author_can_delete_own_post(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/posts/{$post->id}")
            ->assertOk()
            ->assertJsonPath('message', '投稿を削除しました。');

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'status' => 'deleted',
        ]);
    }

    public function test_user_cannot_delete_other_users_post(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($other);

        $this->deleteJson("/api/posts/{$post->id}")->assertForbidden();

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'status' => 'published',
        ]);
    }

    public function test_guest_cannot_delete_post(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);

        $this->deleteJson("/api/posts/{$post->id}")->assertUnauthorized();
    }

    public function test_deleted_post_is_not_listed(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);
        $post->update(['status' => 'deleted']);

        $this->getJson('/api/posts')
            ->assertOk()
            ->assertJsonCount(0, 'posts');
    }

    public function test_deleted_post_cannot_be_viewed_even_by_author(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($user, $category);
        $post->update(['status' => 'deleted']);

        $token = $user->createToken('openpersona_token')->plainTextToken;

        $this->getJson("/api/posts/{$post->id}", [
            'Authorization' => "Bearer {$token}",
        ])->assertNotFound();
    }

    public function test_deleted_post_comments_are_not_visible(): void
    {
        $author = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Comment::create([
            'post_id' => $post->id,
            'user_id' => $commenter->id,
            'body' => 'コメント',
        ]);

        $post->update(['status' => 'deleted']);

        $this->getJson("/api/posts/{$post->id}")->assertNotFound();
    }
}
