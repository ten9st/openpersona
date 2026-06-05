<?php

namespace App\Http\Controllers;

use App\Models\Bookmark;
use App\Models\Post;
use App\Support\PostListPresenter;
use Illuminate\Http\Request;

class BookmarkController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $bookmarks = Bookmark::query()
            ->where('user_id', $user->id)
            ->whereHas('post', fn ($query) => $query
                ->where('status', 'published'))
            ->with([
                'post' => fn ($query) => $query
                    ->select(PostListPresenter::selectColumns())
                    ->withCount(['bookmarks as bookmark_count'])
                    ->with(PostListPresenter::eagerLoads()),
            ])
            ->latest()
            ->get();

        $posts = $bookmarks
            ->map(fn (Bookmark $bookmark) => PostListPresenter::format($bookmark->post, true))
            ->values()
            ->all();

        return response()->json([
            'posts' => $posts,
        ]);
    }

    public function store(Request $request, Post $post)
    {
        if ($post->status !== 'published') {
            abort(404);
        }

        Bookmark::firstOrCreate([
            'user_id' => $request->user()->id,
            'post_id' => $post->id,
        ]);

        return response()->json([
            'message' => '付箋を追加しました。',
            'bookmark_count' => $this->bookmarkCount($post),
            'is_bookmarked' => true,
        ]);
    }

    public function destroy(Request $request, Post $post)
    {
        Bookmark::query()
            ->where('user_id', $request->user()->id)
            ->where('post_id', $post->id)
            ->delete();

        return response()->json([
            'message' => '付箋を解除しました。',
            'bookmark_count' => $this->bookmarkCount($post),
            'is_bookmarked' => false,
        ]);
    }

    private function bookmarkCount(Post $post): int
    {
        return Bookmark::query()
            ->where('post_id', $post->id)
            ->count();
    }
}
