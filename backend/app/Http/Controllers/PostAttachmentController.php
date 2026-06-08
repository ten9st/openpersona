<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostAttachment;
use App\Rules\PostAttachmentFile;
use App\Support\PostAttachmentPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class PostAttachmentController extends Controller
{
    public function store(Request $request, Post $post)
    {
        Gate::authorize('attach', $post);

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', new PostAttachmentFile],
        ]);

        $attachments = [];

        foreach ($validated['files'] as $file) {
            $path = $file->store("attachments/{$post->id}", 'public');

            $attachment = $post->attachments()->create([
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => PostAttachmentFile::resolveType($file),
                'file_size' => $file->getSize(),
            ]);

            $attachments[] = PostAttachmentPresenter::format($attachment);
        }

        return response()->json([
            'message' => '添付ファイルをアップロードしました。',
            'attachments' => $attachments,
        ], 201);
    }

    public function destroy(Post $post, PostAttachment $attachment)
    {
        Gate::authorize('attach', $post);

        if ((int) $attachment->post_id !== (int) $post->id) {
            abort(404);
        }

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();

        return response()->json([
            'message' => '添付ファイルを削除しました。',
        ]);
    }
}
