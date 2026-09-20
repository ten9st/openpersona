<?php

namespace Tests\Feature;

use App\Domain\Post\Models\Tag;
use App\Domain\Post\QueryServices\TagQueryService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_search_tags(): void
    {
        Tag::create(['name' => 'エネルギー', 'slug' => 'energy']);
        Tag::create(['name' => '経済政策', 'slug' => 'economy-policy']);

        $this->getJson('/api/tags?search='.urlencode('エネ'))
            ->assertOk()
            ->assertJsonCount(1, 'tags')
            ->assertJsonPath('tags.0.name', 'エネルギー');
    }

    public function test_guest_can_list_tags_without_search(): void
    {
        Tag::create(['name' => '科学', 'slug' => 'science']);

        $this->getJson('/api/tags')
            ->assertOk()
            ->assertJsonCount(1, 'tags');
    }

    public function test_guest_cannot_create_tag(): void
    {
        $this->postJson('/api/tags', ['name' => '新規タグ'])
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_tag(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/tags', ['name' => '新規タグ'])
            ->assertCreated()
            ->assertJsonPath('tag.name', '新規タグ')
            ->assertJsonPath('created', true);

        $this->assertDatabaseHas('tags', [
            'name' => '新規タグ',
        ]);
    }

    public function test_store_returns_existing_tag_when_name_duplicates(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $existing = Tag::create([
            'name' => '既存タグ',
            'slug' => 'existing-tag',
        ]);

        $this->postJson('/api/tags', ['name' => '既存タグ'])
            ->assertOk()
            ->assertJsonPath('tag.id', $existing->id)
            ->assertJsonPath('created', false);

        $this->assertSame(1, Tag::count());
    }

    public function test_index_returns_empty_array_when_nothing_matches(): void
    {
        Tag::create(['name' => 'energy', 'slug' => 'energy']);

        $this->getJson('/api/tags?search=zzz')
            ->assertOk()
            ->assertExactJson(['tags' => []]);
    }

    public function test_index_returns_only_id_name_slug(): void
    {
        $tag = Tag::create(['name' => 'energy', 'slug' => 'energy']);

        $this->getJson('/api/tags')
            ->assertOk()
            ->assertExactJson(['tags' => [
                ['id' => $tag->id, 'name' => 'energy', 'slug' => 'energy'],
            ]]);
    }

    public function test_index_orders_by_name_and_limits_to_20(): void
    {
        // 作成順を名前順と逆にする(照合順序差を避けるため小文字ASCII)
        foreach (array_reverse(range('a', 'y')) as $letter) {
            Tag::create(['name' => 'tag-'.$letter, 'slug' => 'slug-'.$letter]);
        }

        $names = collect($this->getJson('/api/tags')->assertOk()->json('tags'))->pluck('name');

        $this->assertSame(
            collect(range('a', 't'))->map(fn ($l) => 'tag-'.$l)->all(),
            $names->all(),
        );
    }

    public function test_search_is_partial_match_and_still_ordered_and_limited(): void
    {
        foreach (array_reverse(range('a', 'y')) as $letter) {
            Tag::create(['name' => 'hit-'.$letter, 'slug' => 'hit-'.$letter]);
        }
        Tag::create(['name' => 'other', 'slug' => 'other']);

        $names = collect($this->getJson('/api/tags?search=it-')->assertOk()->json('tags'))->pluck('name');

        $this->assertCount(20, $names);
        $this->assertSame('hit-a', $names->first());
        $this->assertSame('hit-t', $names->last());
    }

    public function test_search_rejects_invalid_type_and_too_long_value(): void
    {
        $this->getJson('/api/tags?search[]=a')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');

        $this->getJson('/api/tags?search='.str_repeat('a', 256))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');

        $this->getJson('/api/tags?search='.str_repeat('a', 255))->assertOk();
    }

    public function test_empty_search_and_zero_search_do_not_filter(): void
    {
        // 既存挙動: !empty() 判定のため、空文字と "0" は絞り込み条件にならない
        Tag::create(['name' => 'zero0', 'slug' => 'zero0']);
        Tag::create(['name' => 'abc', 'slug' => 'abc']);

        $this->getJson('/api/tags?search=')->assertOk()->assertJsonCount(2, 'tags');
        $this->getJson('/api/tags?search=0')->assertOk()->assertJsonCount(2, 'tags');
        $this->getJson('/api/tags?search=00')->assertOk()->assertJsonCount(0, 'tags');
    }

    public function test_query_service_does_not_write(): void
    {
        Tag::create(['name' => 'a', 'slug' => 'a']);
        Tag::create(['name' => 'b', 'slug' => 'b']);
        $before = Tag::query()->orderBy('id')->get()->toArray();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        app(TagQueryService::class)->search('a');

        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertStringStartsWith('select', strtolower(ltrim($sql)));
        }
        $this->assertSame($before, Tag::query()->orderBy('id')->get()->toArray());
    }

    public function test_store_response_has_expected_shape(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/tags', ['name' => 'Hello World'])
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('tag.name', 'Hello World')
            ->assertJsonPath('tag.slug', 'hello-world');

        $tag = Tag::firstOrFail();
        $this->assertSame(['id', 'name', 'slug'], array_keys($response->json('tag')));
        $this->assertSame($tag->id, $response->json('tag.id'));
    }

    public function test_store_repeated_request_reuses_tag(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $first = $this->postJson('/api/tags', ['name' => 'repeat'])->assertCreated();
        $this->postJson('/api/tags', ['name' => 'repeat'])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('tag.id', $first->json('tag.id'));
        $this->postJson('/api/tags', ['name' => 'repeat'])->assertOk();

        $this->assertSame(1, Tag::count());
    }

    public function test_store_trims_name_and_reuses_existing_tag(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/tags', ['name' => '  padded  '])
            ->assertCreated()
            ->assertJsonPath('tag.name', 'padded');

        $existing = Tag::firstOrFail();

        $this->postJson('/api/tags', ['name' => "\tpadded "])
            ->assertOk()
            ->assertJsonPath('tag.id', $existing->id)
            ->assertJsonPath('created', false);

        $this->assertSame(1, Tag::count());
    }

    public function test_store_rejects_invalid_names_without_saving(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // 空文字・空白のみはTrimStrings/ConvertEmptyStringsToNullによりnullとなり、
        // コントローラの独自メッセージではなくrequiredの標準エラーになる
        $payloads = [
            'missing' => [],
            'empty' => ['name' => ''],
            'spaces only' => ['name' => '   '],
            'full-width space only' => ['name' => "\u{3000}"],
            'array' => ['name' => ['a']],
            'integer' => ['name' => 123],
            '256 chars' => ['name' => str_repeat('a', 256)],
        ];

        foreach ($payloads as $label => $payload) {
            $this->postJson('/api/tags', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('name');
            $this->assertSame(0, Tag::count(), $label);
        }
    }

    public function test_store_accepts_255_char_name(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $name = str_repeat('a', 255);

        $this->postJson('/api/tags', ['name' => $name])
            ->assertCreated()
            ->assertJsonPath('tag.name', $name);
    }

    public function test_store_avoids_slug_collision_for_different_names(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertSame(Str::slug('Hello World'), Str::slug('hello-world'));

        $slugs = [];
        foreach (['Hello World', 'hello-world', 'HELLO world'] as $name) {
            $slugs[] = $this->postJson('/api/tags', ['name' => $name])
                ->assertCreated()
                ->json('tag.slug');
        }

        $this->assertSame(['hello-world', 'hello-world-1', 'hello-world-2'], $slugs);
    }

    public function test_store_generates_fallback_slug_when_candidate_is_empty(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $names = ['!!!', 'タグ'];
        foreach ($names as $name) {
            $this->assertSame('', Str::slug($name));

            $this->postJson('/api/tags', ['name' => $name])
                ->assertCreated()
                ->assertJsonPath('tag.slug', 'tag-'.substr(md5($name), 0, 12));
        }

        $this->assertSame(2, Tag::query()->distinct()->count('slug'));
    }
}
