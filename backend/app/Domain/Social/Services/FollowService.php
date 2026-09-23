<?php

namespace App\Domain\Social\Services;

use App\Models\Follow;
use App\Models\User;

class FollowService
{
    /**
     * $followerが$targetをフォローする。自分自身のフォローは禁止する
     * (既存と同じ403・メッセージ)。重複登録は既存のfirstOrCreateと
     * follows(follower_user_id, followed_user_id)の一意制約で防ぐ。
     * HTTPレスポンスの組み立ては行わない。
     */
    public function follow(User $follower, User $target): void
    {
        if ((int) $follower->id === (int) $target->id) {
            abort(403, '自分自身をフォローすることはできません。');
        }

        Follow::firstOrCreate([
            'follower_user_id' => $follower->id,
            'followed_user_id' => $target->id,
        ]);
    }

    /**
     * $followerによる$targetへのフォローを解除する。フォロー関係が
     * 存在しない場合も既存同様にエラーとせず正常に完了する。
     * HTTPレスポンスの組み立ては行わない。
     */
    public function unfollow(User $follower, User $target): void
    {
        Follow::query()
            ->where('follower_user_id', $follower->id)
            ->where('followed_user_id', $target->id)
            ->delete();
    }
}
