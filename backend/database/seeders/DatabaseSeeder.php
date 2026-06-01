<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * 公開設定のデフォルト（旧 profiles の初期値に相当）
     *
     * @return array<string, bool>
     */
    private function defaultProfileVisibilities(): array
    {
        return [
            'last_name' => true,
            'first_name' => false,
            'full_name' => false,
            'age' => true,
            'biography' => false,
            'occupation' => false,
            'region' => false,
        ];
    }

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $now = now();

        $categories = [
            ['name' => '政治', 'slug' => 'politics', 'posting_age_limit' => 18, 'sort_order' => 1],
            ['name' => '社会', 'slug' => 'society', 'posting_age_limit' => null, 'sort_order' => 2],
            ['name' => '経済', 'slug' => 'economy', 'posting_age_limit' => null, 'sort_order' => 3],
            ['name' => '科学', 'slug' => 'science', 'posting_age_limit' => null, 'sort_order' => 4],
            ['name' => '文化', 'slug' => 'culture', 'posting_age_limit' => null, 'sort_order' => 5],
        ];

        foreach ($categories as $category) {
            Category::query()->firstOrCreate(
                ['slug' => $category['slug']],
                $category + ['created_at' => $now, 'updated_at' => $now]
            );
        }

        $user = User::factory()->create([
            'email' => 'test@example.com',
            'last_name' => '山田',
            'first_name' => '太郎',
            'birthdate' => '1990-01-01',
        ]);

        DB::table('profiles')->insert([
            'user_id' => $user->id,
            'biography' => null,
            'occupation' => null,
            'region' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $visibilityRows = [];
        foreach ($this->defaultProfileVisibilities() as $fieldName => $isPublic) {
            $visibilityRows[] = [
                'user_id' => $user->id,
                'field_name' => $fieldName,
                'is_public' => $isPublic,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('profile_visibilities')->insert($visibilityRows);
    }
}
