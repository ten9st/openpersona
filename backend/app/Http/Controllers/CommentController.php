<?php

namespace App\Http\Controllers;

use App\Domain\Post\Models\Comment;
use App\Domain\Post\Models\Post;
use App\Domain\Profile\QueryServices\PublicProfileQueryService;
use App\Support\PublicProfilePresenter;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function store(Request $request, Post $post)
    {
        if ($post->status !== 'published') {
            abort(404);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment = Comment::create([
            'post_id' => $post->id,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

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
