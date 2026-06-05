<?php

namespace App\Http\Controllers;

use App\Models\Follow;
use App\Models\Post;
use App\Models\User;
use App\Support\PostListPresenter;
use App\Support\PublicProfilePresenter;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    public function timeline(Request $request)
    {
        $followedUserIds = Follow::query()
            ->where('follower_user_id', $request->user()->id)
            ->pluck('followed_user_id');

        if ($followedUserIds->isEmpty()) {
            return response()->json([
                'posts' => [],
            ]);
        }

        $posts = Post::query()
            ->select(PostListPresenter::selectColumns())
            ->withCount(['bookmarks as bookmark_count'])
            ->with(PostListPresenter::eagerLoads())
            ->whereIn('user_id', $followedUserIds)
            ->where('status', 'published')
            ->latest('published_at')
            ->get()
            ->map(fn (Post $post) => PostListPresenter::format($post))
            ->values()
            ->all();

        return response()->json([
            'posts' => $posts,
        ]);
    }

    public function followers(Request $request, User $user)
    {
        $users = Follow::query()
            ->where('followed_user_id', $user->id)
            ->with('follower')
            ->latest()
            ->get()
            ->map(fn (Follow $follow) => PublicProfilePresenter::summary($follow->follower))
            ->values()
            ->all();

        return response()->json([
            'users' => $users,
        ]);
    }

    public function following(Request $request, User $user)
    {
        $users = Follow::query()
            ->where('follower_user_id', $user->id)
            ->with('followed')
            ->latest()
            ->get()
            ->map(fn (Follow $follow) => PublicProfilePresenter::summary($follow->followed))
            ->values()
            ->all();

        return response()->json([
            'users' => $users,
        ]);
    }

    public function store(Request $request, User $user)
    {
        $follower = $request->user();

        if ((int) $follower->id === (int) $user->id) {
            abort(403, '自分自身をフォローすることはできません。');
        }

        Follow::firstOrCreate([
            'follower_user_id' => $follower->id,
            'followed_user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'フォローしました。',
            'followers_count' => $this->followersCount($user),
            'following_count' => $this->followingCount($user),
            'is_following' => true,
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        Follow::query()
            ->where('follower_user_id', $request->user()->id)
            ->where('followed_user_id', $user->id)
            ->delete();

        return response()->json([
            'message' => 'フォローを解除しました。',
            'followers_count' => $this->followersCount($user),
            'following_count' => $this->followingCount($user),
            'is_following' => false,
        ]);
    }

    private function followersCount(User $user): int
    {
        return Follow::query()
            ->where('followed_user_id', $user->id)
            ->count();
    }

    private function followingCount(User $user): int
    {
        return Follow::query()
            ->where('follower_user_id', $user->id)
            ->count();
    }
}
