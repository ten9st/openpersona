<?php

namespace App\Domain\Post\Services;

use App\Domain\Post\Models\Comment;
use App\Domain\Post\Models\Post;
use App\Models\User;

class CommentService
{
    /**
     * $postが公開状態でなければ既存と同じ404にする。入力検証より先に
     * 呼び出すことで、不正な本文でも下書き・論理削除済み投稿には404が
     * 優先される既存の順序を維持できる。create()内部からも呼ぶため、
     * サービスを直接利用した場合も非公開投稿への作成を防げる。
     */
    public function ensurePostIsCommentable(Post $post): void
    {
        if ($post->status !== 'published') {
            abort(404);
        }
    }

    /**
     * $user(認証ユーザー)による$post(ルートで解決した投稿)へのコメントを
     * 作成する。$bodyは呼び出し元で検証済みの値を渡す。post_id/user_idは
     * リクエストの値を使わず、常に引数の$post/$userから設定する。
     * HTTPレスポンスの組み立ては行わない。
     */
    public function create(User $user, Post $post, string $body): Comment
    {
        $this->ensurePostIsCommentable($post);

        return Comment::create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);
    }
}
