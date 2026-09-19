<?php

namespace Tests\Feature;

use App\Domain\Post\Models\Bookmark;
use App\Domain\Post\Models\Category;
use App\Domain\Post\Models\Post;
use App\Domain\Post\QueryServices\BookmarkQueryService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookmarkQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_methods_do_not_write_to_the_database(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();

        $category = Category::create([
            'name' => '政治',
            'slug' => 'politics',
            'sort_order' => 1,
        ]);

        $post = Post::create([
            'user_id' => $author->id,
            'category_id' => $category->id,
            'title' => '公開投稿',
            'body' => '本文です。',
            'status' => 'published',
            'published_at' => now(),
        ]);

        Bookmark::create(['user_id' => $viewer->id, 'post_id' => $post->id]);

        $bookmarkCountBefore = Bookmark::query()->count();

        $service = app(BookmarkQueryService::class);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $service->bookmarkedPosts($viewer);
        $service->bookmarkCount($post);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $this->assertSame(
                'select',
                strtolower(explode(' ', trim($query['query']))[0]),
                'BookmarkQueryServiceがSELECT以外のクエリを発行しています: '.$query['query']
            );
        }

        $this->assertSame(
            $bookmarkCountBefore,
            Bookmark::query()->count(),
            'BookmarkQueryServiceの呼び出しでbookmarksテーブルが変化してしまっています。'
        );
    }
}
