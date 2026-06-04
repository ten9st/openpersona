<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_list_categories_ordered_by_sort_order(): void
    {
        Category::create([
            'name' => '社会',
            'slug' => 'society',
            'sort_order' => 2,
        ]);

        Category::create([
            'name' => '政治',
            'slug' => 'politics',
            'sort_order' => 1,
        ]);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(2, 'categories')
            ->assertJsonPath('categories.0.name', '政治')
            ->assertJsonPath('categories.1.name', '社会');
    }
}
