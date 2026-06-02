<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Post;
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
            'user.profile:id,user_id,region',
            'user.profileVisibilities' => fn ($query) => $query
                ->select(['id', 'user_id', 'field_name', 'is_public'])
                ->where('field_name', 'first_name'),
            'user.identityVerifications:id,user_id,verification_status',
        ]);

        $commentArray = $comment->toArray();
        $commentArray['user'] = PublicProfilePresenter::summary($comment->user);

        return response()->json([
            'message' => 'コメントを投稿しました。',
            'comment' => $commentArray,
        ], 201);
    }
}
