<?php

namespace App\Http\Controllers;

use App\Domain\Post\Exceptions\PostAttachmentOperationFailedException;
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

        try {
            $attachments = $this->postAttachmentService->store($post, $validated['files']);
        } catch (PostAttachmentOperationFailedException $e) {
            report($e);

            return response()->json([
                'message' => '添付ファイルのアップロードに失敗しました。時間をおいて再度お試しください。',
            ], 500);
        }

        return response()->json([
            'message' => '添付ファイルをアップロードしました。',
            'attachments' => collect($attachments)
                ->map(fn (PostAttachment $attachment) => PostAttachmentPresenter::format($attachment))
                ->all(),
        ], 201);
    }

    public function destroy(Post $post, PostAttachment $attachment)
    {
        Gate::authorize('detachAttachment', $post);

        try {
            $this->postAttachmentService->destroy($post, $attachment);
        } catch (PostAttachmentOperationFailedException $e) {
            report($e);

            return response()->json([
                'message' => '添付ファイルの削除に失敗しました。時間をおいて再度お試しください。',
            ], 500);
        }

        return response()->json([
            'message' => '添付ファイルを削除しました。',
        ]);
    }
}
