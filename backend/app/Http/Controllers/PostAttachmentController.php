<?php

namespace App\Http\Controllers;

use App\Domain\Post\Models\Post;
use App\Domain\Post\Models\PostAttachment;
use App\Domain\Post\Presenters\PostAttachmentPresenter;
use App\Domain\Post\Rules\PostAttachmentFile;
use App\Domain\Post\Services\PostAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PostAttachmentController extends Controller
{
    public function __construct(
        private PostAttachmentService $postAttachmentService,
    ) {}

    public function store(Request $request, Post $post)
    {
        Gate::authorize('attach', $post);

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', new PostAttachmentFile],
        ]);

        $attachments = $this->postAttachmentService->store($post, $validated['files']);

        return response()->json([
            'message' => '添付ファイルをアップロードしました。',
            'attachments' => collect($attachments)
                ->map(fn (PostAttachment $attachment) => PostAttachmentPresenter::format($attachment))
                ->all(),
        ], 201);
    }

    public function destroy(Post $post, PostAttachment $attachment)
    {
        Gate::authorize('attach', $post);

        $this->postAttachmentService->destroy($post, $attachment);

        return response()->json([
            'message' => '添付ファイルを削除しました。',
        ]);
    }
}
