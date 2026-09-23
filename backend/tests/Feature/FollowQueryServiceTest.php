<?php

namespace Tests\Feature;

use App\Domain\Social\QueryServices\FollowQueryService;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FollowQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_methods_do_not_write_to_the_database(): void
    {
        $target = User::factory()->create();
        $follower = User::factory()->create();

        Follow::create([
            'follower_user_id' => $follower->id,
            'followed_user_id' => $target->id,
        ]);

        $followCountBefore = Follow::query()->count();

        $service = app(FollowQueryService::class);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $service->followers($target);
        $service->following($follower);
        $service->followersCount($target);
        $service->followingCount($follower);
        $service->followedUserIds($follower);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $this->assertSame(
                'select',
                strtolower(explode(' ', trim($query['query']))[0]),
                'FollowQueryServiceがSELECT以外のクエリを発行しています: '.$query['query']
            );
        }

        $this->assertSame(
            $followCountBefore,
            Follow::query()->count(),
            'FollowQueryServiceの呼び出しでfollowsテーブルが変化してしまっています。'
        );
    }
}
