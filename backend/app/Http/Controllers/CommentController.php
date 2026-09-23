<?php

namespace App\Http\Controllers;

use App\Domain\Post\Models\Post;
use App\Domain\Post\Services\CommentService;
use App\Domain\Profile\QueryServices\PublicProfileQueryService;
use App\Support\PublicProfilePresenter;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function __construct(
        private CommentService $commentService,
    ) {}

    public function store(Request $request, Post $post)
    {
        // 本文の検証より先に投稿の公開状態を確認し、下書き・論理削除済み
        // 投稿には既存どおり404を優先させる(422より先に404にする)。
        $this->commentService->ensurePostIsCommentable($post);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment = $this->commentService->create($request->user(), $post, $validated['body']);

        $comment->load([
            'user:id,last_name,first_name,birthdate',
            ...PublicProfileQueryService::summaryRelations('user'),
        ]);

        $commentArray = $comment->toArray();
        $commentArray['user'] = PublicProfilePresenter::summary($comment->user);

        return response()->json([
            'message' => 'コメントを投稿しました。',
            'comment' => $commentArray,
        ], 201);
    }
}
