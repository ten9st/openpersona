<?php

namespace App\Http\Controllers;

use App\Domain\Post\Models\Post;
use App\Domain\Post\Presenters\PostPresenter;
use App\Domain\Post\QueryServices\PostQueryService;
use App\Domain\Social\QueryServices\FollowQueryService;
use App\Domain\Social\Services\FollowService;
use App\Models\User;
use App\Support\PublicProfilePresenter;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    public function __construct(
        private PostQueryService $postQueryService,
        private FollowQueryService $followQueryService,
        private FollowService $followService,
    ) {}

    public function timeline(Request $request)
    {
        $followedUserIds = $this->followQueryService->followedUserIds($request->user());

        if ($followedUserIds->isEmpty()) {
            return response()->json([
                'posts' => [],
            ]);
        }

        $posts = $this->postQueryService->publishedByUserIds($followedUserIds)
            ->map(fn (Post $post) => PostPresenter::format($post))
            ->values()
            ->all();

        return response()->json([
            'posts' => $posts,
        ]);
    }

    public function followers(Request $request, User $user)
    {
        $users = $this->followQueryService->followers($user)
            ->map(fn (User $follower) => PublicProfilePresenter::summary($follower))
            ->values()
            ->all();

        return response()->json([
            'users' => $users,
        ]);
    }

    public function following(Request $request, User $user)
    {
        $users = $this->followQueryService->following($user)
            ->map(fn (User $followed) => PublicProfilePresenter::summary($followed))
            ->values()
            ->all();

        return response()->json([
            'users' => $users,
        ]);
    }

    public function store(Request $request, User $user)
    {
        $this->followService->follow($request->user(), $user);

        return response()->json([
            'message' => 'フォローしました。',
            'followers_count' => $this->followQueryService->followersCount($user),
            'following_count' => $this->followQueryService->followingCount($user),
            'is_following' => true,
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        $this->followService->unfollow($request->user(), $user);

        return response()->json([
            'message' => 'フォローを解除しました。',
            'followers_count' => $this->followQueryService->followersCount($user),
            'following_count' => $this->followQueryService->followingCount($user),
            'is_following' => false,
        ]);
    }
}
