<?php

namespace Tests\Feature;

use App\Domain\Post\Models\Bookmark;
use App\Domain\Post\Models\Category;
use App\Domain\Post\Models\Post;
use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_unbookmarking_without_existing_bookmark_succeeds(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Sanctum::actingAs($viewer);

        $this->deleteJson("/api/posts/{$post->id}/bookmark")
            ->assertOk()
            ->assertJsonPath('is_bookmarked', false)
            ->assertJsonPath('bookmark_count', 0);
    }

    public function test_repeated_unbookmark_succeeds(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Bookmark::create(['user_id' => $viewer->id, 'post_id' => $post->id]);

        Sanctum::actingAs($viewer);

        $this->deleteJson("/api/posts/{$post->id}/bookmark")->assertOk();
        $this->deleteJson("/api/posts/{$post->id}/bookmark")
            ->assertOk()
            ->assertJsonPath('is_bookmarked', false)
            ->assertJsonPath('bookmark_count', 0);

        $this->assertSame(0, Bookmark::query()->count());
    }

    public function test_can_remove_bookmark_after_post_becomes_unpublished(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Bookmark::create(['user_id' => $viewer->id, 'post_id' => $post->id]);

        // 追加後に投稿が非公開(下書き)になっても、既存の付箋は
        // 解除できる(追加側の公開条件を解除側へ流用しない)。
        $post->update(['status' => 'draft']);

        Sanctum::actingAs($viewer);

        $this->deleteJson("/api/posts/{$post->id}/bookmark")
            ->assertOk()
            ->assertJsonPath('is_bookmarked', false);

        $this->assertDatabaseMissing('bookmarks', [
            'user_id' => $viewer->id,
            'post_id' => $post->id,
        ]);
    }

    public function test_bookmark_operations_do_not_affect_other_users_bookmarks(): void
    {
        $author = User::factory()->create();
        $viewerA = User::factory()->create();
        $viewerB = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Bookmark::create(['user_id' => $viewerB->id, 'post_id' => $post->id]);

        Sanctum::actingAs($viewerA);

        $this->postJson("/api/posts/{$post->id}/bookmark")->assertOk();

        $this->assertDatabaseHas('bookmarks', [
            'user_id' => $viewerB->id,
            'post_id' => $post->id,
        ]);

        $this->deleteJson("/api/posts/{$post->id}/bookmark")->assertOk();

        $this->assertDatabaseHas('bookmarks', [
            'user_id' => $viewerB->id,
            'post_id' => $post->id,
        ]);
        $this->assertSame(1, Bookmark::query()->count());
    }

    public function test_bookmark_count_reflects_multiple_users_after_add_and_remove(): void
    {
        $author = User::factory()->create();
        $viewerA = User::factory()->create();
        $viewerB = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Bookmark::create(['user_id' => $viewerB->id, 'post_id' => $post->id]);

        Sanctum::actingAs($viewerA);

        $this->postJson("/api/posts/{$post->id}/bookmark")
            ->assertOk()
            ->assertJsonPath('bookmark_count', 2);

        $this->deleteJson("/api/posts/{$post->id}/bookmark")
            ->assertOk()
            ->assertJsonPath('bookmark_count', 1);
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

    public function test_bookmarked_posts_list_is_empty_when_no_bookmarks(): void
    {
        $viewer = User::factory()->create();

        Sanctum::actingAs($viewer);

        $this->getJson('/api/bookmarks')
            ->assertOk()
            ->assertJsonPath('posts', []);
    }

    public function test_bookmarked_posts_list_only_includes_own_bookmarks(): void
    {
        $author = User::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $category = $this->createCategory();

        $postForA = $this->createPublishedPost($author, $category, 'Aの付箋投稿');
        Bookmark::create(['user_id' => $userA->id, 'post_id' => $postForA->id]);

        $postForB = $this->createPublishedPost($author, $category, 'Bの付箋投稿');
        Bookmark::create(['user_id' => $userB->id, 'post_id' => $postForB->id]);

        Sanctum::actingAs($userA);

        $this->getJson('/api/bookmarks')
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('posts.0.id', $postForA->id)
            ->assertJsonPath('posts.0.title', 'Aの付箋投稿');

        Sanctum::actingAs($userB);

        $this->getJson('/api/bookmarks')
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('posts.0.id', $postForB->id)
            ->assertJsonPath('posts.0.title', 'Bの付箋投稿');
    }

    public function test_bookmarked_posts_list_excludes_draft_and_deleted_posts(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();

        $published = $this->createPublishedPost($author, $category, '公開投稿');
        Bookmark::create(['user_id' => $viewer->id, 'post_id' => $published->id]);

        $draft = Post::create([
            'user_id' => $author->id,
            'category_id' => $category->id,
            'title' => '下書き',
            'body' => '本文',
            'status' => 'draft',
        ]);
        Bookmark::create(['user_id' => $viewer->id, 'post_id' => $draft->id]);

        $deleted = $this->createPublishedPost($author, $category, '削除済み投稿');
        $deleted->update(['status' => 'deleted']);
        Bookmark::create(['user_id' => $viewer->id, 'post_id' => $deleted->id]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/bookmarks')
            ->assertOk()
            ->assertJsonCount(1, 'posts')
            ->assertJsonPath('posts.0.id', $published->id);
    }

    public function test_bookmarked_posts_list_orders_by_bookmark_created_at_not_post_published_at(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();

        // 投稿の公開日時の順序(A→B)と、付箋を付けた順序(B→A)を
        // あえて逆にする。
        $postA = $this->createPublishedPost($author, $category, '投稿A');
        $postA->update(['published_at' => now()]);

        $postB = $this->createPublishedPost($author, $category, '投稿B');
        $postB->update(['published_at' => now()->subDay()]);

        // 投稿Aの公開が新しいが、付箋は投稿Aを古く・投稿Bを新しく付ける。
        $bookmarkA = Bookmark::create(['user_id' => $viewer->id, 'post_id' => $postA->id])
            ->forceFill(['created_at' => now()->subMinutes(2)]);
        $bookmarkA->save();

        $bookmarkB = Bookmark::create(['user_id' => $viewer->id, 'post_id' => $postB->id])
            ->forceFill(['created_at' => now()->subMinute()]);
        $bookmarkB->save();

        // 保存後の日時が意図した大小関係になっていることを確認する。
        $this->assertTrue(
            $postA->fresh()->published_at->gt($postB->fresh()->published_at),
            '事前条件: 投稿Aの公開日時が投稿Bより新しいはず。'
        );
        $this->assertTrue(
            $bookmarkB->fresh()->created_at->gt($bookmarkA->fresh()->created_at),
            '事前条件: 投稿Bの付箋作成日時が投稿Aより新しいはず。'
        );

        Sanctum::actingAs($viewer);

        // 投稿の公開日時順ならA→Bだが、付箋の作成日時順ならB(後で付けた)→Aの順。
        $this->getJson('/api/bookmarks')
            ->assertOk()
            ->assertJsonPath('posts.0.title', '投稿B')
            ->assertJsonPath('posts.1.title', '投稿A');
    }

    public function test_bookmarked_posts_list_respects_visibility_settings(): void
    {
        $author = User::factory()->create(['first_name' => '非公開名']);
        Profile::create(['user_id' => $author->id]);
        ProfileVisibility::create([
            'user_id' => $author->id,
            'field_name' => 'first_name',
            'is_public' => false,
        ]);

        $viewer = User::factory()->create();
        $category = $this->createCategory();
        $post = $this->createPublishedPost($author, $category);

        Bookmark::create(['user_id' => $viewer->id, 'post_id' => $post->id]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/bookmarks')
            ->assertOk()
            ->assertJsonPath('posts.0.user.first_name', null);
    }

    public function test_bookmarked_posts_list_does_not_increase_queries_with_more_authors(): void
    {
        $viewer = User::factory()->create();
        $category = $this->createCategory();

        for ($i = 0; $i < 3; $i++) {
            $author = User::factory()->create();
            $post = $this->createPublishedPost($author, $category, "投稿{$i}");
            Bookmark::create(['user_id' => $viewer->id, 'post_id' => $post->id]);
        }

        Sanctum::actingAs($viewer);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->getJson('/api/bookmarks');

        $queryCountForThreePosts = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()->assertJsonCount(3, 'posts');

        $extraAuthor = User::factory()->create();
        $extraPost = $this->createPublishedPost($extraAuthor, $category, '追加投稿');
        Bookmark::create(['user_id' => $viewer->id, 'post_id' => $extraPost->id]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/bookmarks')->assertOk();
        $queryCountForFourPosts = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $queryCountForThreePosts,
            $queryCountForFourPosts,
            '投稿者数の増加に応じてクエリ数が増加しています(N+1の可能性があります)。'
        );
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

    public function test_cannot_bookmark_deleted_post(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory();

        $post = $this->createPublishedPost($author, $category);
        $post->update(['status' => 'deleted']);

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

    public function test_user_bookmarks_relation_returns_only_own_bookmarks(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $category = $this->createCategory();
        $ownPost = $this->createPublishedPost($user, $category, '自分がブックマークした投稿');
        $otherPost = $this->createPublishedPost($other, $category, '他人がブックマークした投稿');

        Bookmark::create(['user_id' => $user->id, 'post_id' => $ownPost->id]);
        Bookmark::create(['user_id' => $other->id, 'post_id' => $otherPost->id]);

        $bookmarks = $user->bookmarks;

        $this->assertCount(1, $bookmarks);
        $this->assertSame($ownPost->id, $bookmarks->first()->post_id);
    }
}
