<?php

namespace App\Domain\Post\Services;

use App\Domain\Post\Models\Bookmark;
use App\Domain\Post\Models\Post;
use App\Models\User;

class BookmarkService
{
    /**
     * $userが$postに付箋を追加する。公開投稿のみ許可し、それ以外は
     * 既存と同じ404。重複登録は既存のfirstOrCreateとDB一意制約
     * (user_id, post_id)で防ぐ。HTTPレスポンスの組み立ては行わない。
     */
    public function add(User $user, Post $post): void
    {
        if ($post->status !== 'published') {
            abort(404);
        }

        Bookmark::firstOrCreate([
            'user_id' => $user->id,
            'post_id' => $post->id,
        ]);
    }

    /**
     * $userによる$postへの付箋を解除する。追加側と異なり投稿の状態は
     * 問わない(非公開になった投稿に対する既存の付箋も解除できる、という
     * 既存仕様を維持する)。対象の付箋が存在しない場合も既存同様に
     * 正常完了する。HTTPレスポンスの組み立ては行わない。
     */
    public function remove(User $user, Post $post): void
    {
        Bookmark::query()
            ->where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->delete();
    }
}
