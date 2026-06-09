<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
