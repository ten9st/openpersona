<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\PostSource;
use App\Models\TrustScore;
use App\Models\User;
use App\Services\TrustScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrustScoreSourceTest extends TestCase
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

    private function createPublishedPost(User $user, Category $category, ?string $title = null): Post
    {
        return Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => $title ?? '公開投稿',
            'body' => '本文です。',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function test_source_score_is_zero_when_no_post_sources(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $this->createPublishedPost($user, $category);

        $service = app(TrustScoreService::class);
        $service->calculate($user);

        $trustScore = TrustScore::where('user_id', $user->id)->first();

        $this->assertNotNull($trustScore);
        $this->assertSame(0, $trustScore->source_score);
    }

    public function test_source_score_increases_when_all_published_posts_have_sources(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        $post = $this->createPublishedPost($user, $category);
        PostSource::create([
            'post_id' => $post->id,
            'source_type' => PostSource::TYPE_URL,
            'url' => 'https://example.com/article',
        ]);

        $service = app(TrustScoreService::class);
        $service->calculate($user);

        $trustScore = TrustScore::where('user_id', $user->id)->first();

        $this->assertNotNull($trustScore);
        $this->assertSame(25, $trustScore->source_score);
    }

    public function test_source_score_is_zero_when_user_has_no_published_posts(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        $draft = Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => '下書き',
            'body' => '本文',
            'status' => 'draft',
        ]);

        PostSource::create([
            'post_id' => $draft->id,
            'source_type' => PostSource::TYPE_URL,
            'url' => 'https://example.com/draft-only',
        ]);

        $service = app(TrustScoreService::class);

        $this->assertSame(0, $service->calcSourceScore($user));
    }
}
