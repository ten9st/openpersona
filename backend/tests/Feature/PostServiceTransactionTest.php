<?php

namespace Tests\Feature;

use App\Domain\Post\Models\Category;
use App\Domain\Post\Models\Post;
use App\Domain\Post\Models\PostSource;
use App\Domain\Post\Models\Tag;
use App\Domain\Post\Services\PostService;
use App\Models\TrustScore;
use App\Models\User;
use App\Services\TrustScoreService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FailingTrustScoreService;
use Tests\Support\TrustScoreRecalculationFailedForTest;
use Tests\TestCase;

class PostServiceTransactionTest extends TestCase
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

    private function bindFailingTrustScoreService(int $failOnCall): FailingTrustScoreService
    {
        $spy = new FailingTrustScoreService($failOnCall, new TrustScoreRecalculationFailedForTest(
            "trust score recalculation intentionally failed on call #{$failOnCall} for test"
        ));

        $this->app->instance(TrustScoreService::class, $spy);

        return $spy;
    }

    // ------------------------------------------------------------------
    // store()
    // ------------------------------------------------------------------

    public function test_store_rolls_back_post_sources_and_tags_when_tag_sync_fails_after_sources_are_written(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();
        $validTag1 = Tag::create(['name' => 'タグ1', 'slug' => 'tag-1']);
        $validTag2 = Tag::create(['name' => 'タグ2', 'slug' => 'tag-2']);
        $service = new PostService;

        $caught = null;

        try {
            $service->store($user, [
                'category_id' => $category->id,
                'title' => 'ロールバック確認用投稿',
                'body' => '本文',
                'status' => 'published',
                'sources' => [
                    ['source_type' => 'url', 'title' => 'ソース1', 'url' => 'https://example.com/1'],
                ],
                // 有効な2件の後に存在しないタグIDを混ぜて、タグ同期を失敗させる。
                'tag_ids' => [$validTag1->id, $validTag2->id, 999999],
            ]);
        } catch (QueryException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'タグ同期の失敗時にQueryExceptionが発生するはずです。');
        $this->assertStringContainsString(
            'post_tags',
            $caught->getSql(),
            '想定と異なる箇所で例外が発生しています(post_tagsへの書き込みではありません)。'
        );

        $this->assertSame(
            0,
            Post::where('title', 'ロールバック確認用投稿')->count(),
            'タグ同期の失敗時に投稿がロールバックされずに残ってしまっています。'
        );
        $this->assertSame(
            0,
            PostSource::where('title', 'ソース1')->count(),
            'タグ同期の失敗時にソースがロールバックされずに残ってしまっています。'
        );
        $this->assertSame(
            0,
            DB::table('post_tags')->count(),
            'タグ同期の失敗時にタグの関連付けがロールバックされずに残ってしまっています。'
        );
    }

    public function test_store_rolls_back_observer_triggered_trust_score_update_when_a_later_write_fails(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        $baseline = (int) TrustScore::query()->where('user_id', $user->id)->value('total_score');

        $spy = $this->bindFailingTrustScoreService(failOnCall: 1);
        $service = new PostService;

        $caught = null;

        try {
            // 公開投稿を作成すると投稿数スコアが実際に加算される条件。
            $service->store($user, [
                'category_id' => $category->id,
                'title' => 'スコア変化確認用投稿',
                'body' => '本文',
                'status' => 'published',
            ]);
        } catch (TrustScoreRecalculationFailedForTest $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '想定したタイミングでTrustScoreRecalculationFailedForTestが発生しませんでした。');
        $this->assertSame(1, $spy->callCount());

        $this->assertNotSame(
            $baseline,
            $spy->observedTotalScores[0] ?? null,
            '投稿作成によって信頼度スコアが実際に変化する条件になっていません(テストの前提が不成立)。'
        );

        $this->assertSame(
            0,
            Post::where('title', 'スコア変化確認用投稿')->count(),
            '信頼度スコア更新の失敗時に投稿がロールバックされずに残ってしまっています。'
        );
        $this->assertSame(
            $baseline,
            (int) TrustScore::query()->where('user_id', $user->id)->value('total_score'),
            '信頼度スコア更新の失敗時にスコアがロールバックされずに変化したまま残ってしまっています。'
        );
    }

    // ------------------------------------------------------------------
    // update()
    // ------------------------------------------------------------------

    public function test_update_restores_original_content_and_sources_when_a_later_source_write_fails(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        $post = Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => '元のタイトル',
            'body' => '元の本文',
            'status' => 'draft',
        ]);

        $post->sources()->create([
            'source_type' => 'url',
            'title' => '既存ソース',
            'url' => 'https://example.com/original',
        ]);

        // call #1: $post->update() のobserverによる実計算(成功させる)
        // call #2: 1件目の新ソース作成のobserverによる実計算 → ここで失敗させる
        $spy = $this->bindFailingTrustScoreService(failOnCall: 2);
        $service = new PostService;

        $caught = null;

        try {
            $service->update($post, [
                'title' => '更新後のタイトル',
                'body' => '更新後の本文',
                'status' => 'published',
                'sources' => [
                    ['source_type' => 'url', 'title' => '新ソース1', 'url' => 'https://example.com/new-1'],
                    ['source_type' => 'url', 'title' => '新ソース2', 'url' => 'https://example.com/new-2'],
                ],
            ]);
        } catch (TrustScoreRecalculationFailedForTest $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '想定したタイミングでTrustScoreRecalculationFailedForTestが発生しませんでした。');
        $this->assertSame(2, $spy->callCount(), '2件目のソース作成に到達する前に処理が終わっています。');

        $post->refresh();

        $this->assertSame('元のタイトル', $post->title, 'タイトルが元の内容に復元されていません。');
        $this->assertSame('元の本文', $post->body, '本文が元の内容に復元されていません。');
        $this->assertSame('draft', $post->status, 'ステータスが元の内容に復元されていません。');

        $this->assertSame(1, $post->sources()->count(), 'ソースの件数が元の状態に復元されていません。');
        $this->assertSame('既存ソース', $post->sources()->first()->title, 'ソースの内容が元の状態に復元されていません。');
    }

    // ------------------------------------------------------------------
    // copy()
    // ------------------------------------------------------------------

    public function test_copy_leaves_no_partial_copy_and_does_not_modify_original_when_a_later_source_write_fails(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        $original = Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => '公開投稿',
            'body' => '本文',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $original->sources()->create([
            'source_type' => 'url',
            'title' => '元ソース1',
            'url' => 'https://example.com/original-1',
        ]);
        $original->sources()->create([
            'source_type' => 'url',
            'title' => '元ソース2',
            'url' => 'https://example.com/original-2',
        ]);

        $postCountBefore = Post::count();

        // call #1: コピー先投稿作成のobserverによる実計算(成功させる)
        // call #2: 1件目のソースコピー作成のobserverによる実計算 → ここで失敗させる
        //          (2件目のソースコピーには到達しない)
        $spy = $this->bindFailingTrustScoreService(failOnCall: 2);
        $service = new PostService;

        $caught = null;

        try {
            $service->copy($user, $original);
        } catch (TrustScoreRecalculationFailedForTest $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '想定したタイミングでTrustScoreRecalculationFailedForTestが発生しませんでした。');
        $this->assertSame(2, $spy->callCount(), '2件目のソースコピーに到達する前に処理が終わっています。');

        $this->assertSame(
            $postCountBefore,
            Post::count(),
            '不完全なコピー投稿がロールバックされずに残ってしまっています。'
        );
        $this->assertSame(
            0,
            PostSource::where('title', '元ソース1')->where('post_id', '!=', $original->id)->count(),
            'コピーされたソースがロールバックされずに残ってしまっています。'
        );

        $original->refresh();
        $this->assertSame('公開投稿', $original->title, 'コピー元投稿のタイトルが変更されてしまっています。');
        $this->assertSame(2, $original->sources()->count(), 'コピー元投稿のソース件数が変化してしまっています。');
    }

    // ------------------------------------------------------------------
    // destroy()
    // ------------------------------------------------------------------

    public function test_destroy_restores_original_status_and_trust_score_when_observer_recalculation_fails(): void
    {
        $user = User::factory()->create();
        $category = $this->createCategory();

        // 削除が許可されるのは下書きのみ。
        $post = Post::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => '下書き',
            'body' => '本文',
            'status' => 'draft',
        ]);

        // スコアは公開投稿だけを数えるため、下書きの削除では再計算結果が変わらない。
        // 保存済みスコアを再計算結果と異なる値(古い値)にしておき、observerによる
        // 再計算がスコアを実際に書き換える条件を作る。
        $currentScore = (int) TrustScore::query()->where('user_id', $user->id)->value('total_score');
        $baseline = $currentScore + 1;
        TrustScore::query()->where('user_id', $user->id)->update(['total_score' => $baseline]);

        $spy = $this->bindFailingTrustScoreService(failOnCall: 1);
        $service = new PostService;

        $caught = null;

        try {
            $service->destroy($post);
        } catch (TrustScoreRecalculationFailedForTest $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '想定したタイミングでTrustScoreRecalculationFailedForTestが発生しませんでした。');
        $this->assertSame(1, $spy->callCount());

        $this->assertNotSame(
            $baseline,
            $spy->observedTotalScores[0] ?? null,
            '投稿削除によって信頼度スコアが実際に変化する条件になっていません(テストの前提が不成立)。'
        );

        $post->refresh();
        $this->assertSame('draft', $post->status, '投稿ステータスが元の状態(draft)に復元されていません。');

        $this->assertSame(
            $baseline,
            (int) TrustScore::query()->where('user_id', $user->id)->value('total_score'),
            '信頼度スコアが元の値に復元されていません。'
        );
    }
}
