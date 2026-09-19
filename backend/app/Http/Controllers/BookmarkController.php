<?php

namespace App\Http\Controllers;

use App\Domain\Post\Models\Post;
use App\Domain\Post\Presenters\PostPresenter;
use App\Domain\Post\QueryServices\BookmarkQueryService;
use App\Domain\Post\Services\BookmarkService;
use Illuminate\Http\Request;

class BookmarkController extends Controller
{
    public function __construct(
        private BookmarkQueryService $bookmarkQueryService,
        private BookmarkService $bookmarkService,
    ) {}

    public function index(Request $request)
    {
        $posts = $this->bookmarkQueryService->bookmarkedPosts($request->user())
            ->map(fn (Post $post) => PostPresenter::format($post, true))
            ->values()
            ->all();

        return response()->json([
            'posts' => $posts,
        ]);
    }

    public function store(Request $request, Post $post)
    {
        $this->bookmarkService->add($request->user(), $post);

        return response()->json([
            'message' => '付箋を追加しました。',
            'bookmark_count' => $this->bookmarkQueryService->bookmarkCount($post),
            'is_bookmarked' => true,
        ]);
    }

    public function destroy(Request $request, Post $post)
    {
        $this->bookmarkService->remove($request->user(), $post);

        return response()->json([
            'message' => '付箋を解除しました。',
            'bookmark_count' => $this->bookmarkQueryService->bookmarkCount($post),
            'is_bookmarked' => false,
        ]);
    }
}
