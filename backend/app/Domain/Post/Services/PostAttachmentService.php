<?php

namespace App\Domain\Post\Services;

use App\Domain\Post\Models\Post;
use App\Domain\Post\Models\PostAttachment;
use App\Domain\Post\Rules\PostAttachmentFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PostAttachmentService
{
    /**
     * 認可(Gate::authorize('attach', $post))は呼び出し側(Controller)の
     * 責務であり、ここでは行わない。$filesは呼び出し側で検証済みの
     * UploadedFileの配列を渡す。HTTPレスポンスの組み立ては行わない。
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, PostAttachment>
     */
    public function store(Post $post, array $files): array
    {
        $attachments = [];

        foreach ($files as $file) {
            $path = $file->store("attachments/{$post->id}", 'public');

            $attachments[] = $post->attachments()->create([
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => PostAttachmentFile::resolveType($file),
                'file_size' => $file->getSize(),
            ]);
        }

        return $attachments;
    }

    /**
     * $post配下の$attachmentを削除する。$attachmentが$postに属さない場合は
     * 既存と同じ404にする。この所属確認はサービス内部でも保証するため、
     * Controllerの認可チェックを経由せず直接呼び出した場合も、別投稿の
     * 添付ファイルは削除できない。認可(Gate::authorize('attach', $post))は
     * 呼び出し側(Controller)の責務であり、ここでは行わない。
     * HTTPレスポンスの組み立ては行わない。
     */
    public function destroy(Post $post, PostAttachment $attachment): void
    {
        if ((int) $attachment->post_id !== (int) $post->id) {
            abort(404);
        }

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();
    }
}
