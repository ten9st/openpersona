<?php

namespace App\Domain\Post\QueryServices;

use App\Domain\Post\Models\Bookmark;
use App\Domain\Post\Models\Post;
use App\Domain\Post\Presenters\PostPresenter;
use App\Models\User;
use Illuminate\Support\Collection;

class BookmarkQueryService
{
    /**
     * $user本人の付箋一覧を、付箋を付けた日時(bookmarks.created_at)の
     * 新しい順で取得する。下書き・論理削除済みの投稿は除外し、公開投稿のみ
     * 返す。PostPresenter::format()の整形に必要な関連・bookmark_countを
     * 事前取得済みで返すため、整形中に投稿数に比例した追加のDB問い合わせは
     * 発生しない。書き込みは行わない。
     *
     * @return Collection<int, Post>
     */
    public function bookmarkedPosts(User $user): Collection
    {
        return Bookmark::query()
            ->where('user_id', $user->id)
            ->whereHas('post', fn ($query) => $query
                ->where('status', 'published'))
            ->with([
                'post' => fn ($query) => $query
                    ->select(PostPresenter::selectColumns())
                    ->withCount(['bookmarks as bookmark_count'])
                    ->with(PostPresenter::eagerLoads()),
            ])
            ->latest()
            ->get()
            ->map(fn (Bookmark $bookmark) => $bookmark->post)
            ->values();
    }

    /**
     * $postに対する付箋の総数(全ユーザー分)を取得する。
     */
    public function bookmarkCount(Post $post): int
    {
        return Bookmark::query()
            ->where('post_id', $post->id)
            ->count();
    }
}
