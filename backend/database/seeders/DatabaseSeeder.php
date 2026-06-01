<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use App\Support\ProfileVisibilityDefaults;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $categories = [
            ['name' => '政治', 'slug' => 'politics', 'posting_age_limit' => 18, 'sort_order' => 1],
            ['name' => '社会', 'slug' => 'society', 'posting_age_limit' => null, 'sort_order' => 2],
            ['name' => '経済', 'slug' => 'economy', 'posting_age_limit' => null, 'sort_order' => 3],
            ['name' => '科学', 'slug' => 'science', 'posting_age_limit' => null, 'sort_order' => 4],
            ['name' => '文化', 'slug' => 'culture', 'posting_age_limit' => null, 'sort_order' => 5],
        ];

        foreach ($categories as $category) {
            Category::create($category);
        }

        $user = User::create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'last_name' => '山田',
            'first_name' => '太郎',
            'birthdate' => '1990-01-01',
        ]);

        Profile::create([
            'user_id' => $user->id,
            'biography' => 'OpenPersona のテストユーザーです。',
            'occupation' => 'エンジニア',
            'region' => '東京',
        ]);

        ProfileVisibilityDefaults::seedForUser($user->id);

        Post::create([
            'user_id' => $user->id,
            'category_id' => Category::where('slug', 'politics')->value('id'),
            'title' => 'サンプル投稿',
            'body' => 'シードデータのサンプル本文です。',
            'status' => 'published',
            'published_at' => now(),
        ]);
    }
}
