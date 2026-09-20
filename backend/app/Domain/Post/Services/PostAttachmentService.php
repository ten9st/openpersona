<?php

namespace App\Domain\Post\Services;

use App\Domain\Post\Exceptions\PostAttachmentOperationFailedException;
use App\Domain\Post\Models\Post;
use App\Domain\Post\Models\PostAttachment;
use App\Domain\Post\Rules\PostAttachmentFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PostAttachmentService
{
    // ログのcontextに付与する操作名。保存・DB登録・ファイル削除・DB削除・後片付けを区別する。
    private const OP_STORE_FILE = 'store_file';

    private const OP_REGISTER_ATTACHMENT = 'register_attachment';

    private const OP_DELETE_FILE = 'delete_file';

    private const OP_DELETE_RECORD = 'delete_record';

    private const OP_CLEANUP_FILE = 'cleanup_file';

    /**
     * 認可(Gate::authorize('attach', $post))は呼び出し側(Controller)の
     * 責務であり、ここでは行わない。$filesは呼び出し側で検証済みの
     * UploadedFileの配列を渡す。HTTPレスポンスの組み立ては行わない。
     *
     * ファイルとDBを完全に原子的に扱えるわけではない(ファイル操作は
     * DBトランザクションの対象外)。以下の範囲で今回の呼び出し内の
     * 不整合を軽減する。
     * - 各ファイルの保存に失敗(false/例外)した時点で、今回の呼び出しで
     *   既に保存済みのファイルを後片付けしてから例外を投げる。
     * - 全ファイルの保存後、DB登録(post_attachments作成)を単一の
     *   DB::transaction()にまとめる。登録が1件でも失敗すると、今回の
     *   呼び出しで作成されたDB行はロールバックされ、保存済みファイルは
     *   後片付けされる。
     * - DB::transaction()の呼び出しはリトライ回数を指定していない
     *   (既定の1回、リトライなし)。ファイル保存はこのtransaction()の
     *   外側(呼び出しより前)で完了させているため、リトライが発生しても
     *   ファイル操作が重複実行されることはない。
     *
     * プロセスの強制終了や、DBのコミット結果が分からなくなるような接続
     * 障害までは、この後片付けだけでは保証できない
     * (完了報告に制約として明記する)。
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, PostAttachment>
     *
     * @throws PostAttachmentOperationFailedException
     */
    public function store(Post $post, array $files): array
    {
        // 検証を通過した配列のキーは連番とは限らない(例: files[2]のみ、[1, 0]の順など)。
        // $storedPaths(連番で追記)と$filesを同じ添字で対応づけるため、
        // 走査順を保ったままキーを振り直す。
        $files = array_values($files);
        $storedPaths = [];

        try {
            foreach ($files as $file) {
                $storedPaths[] = $this->storeFileOrFail($post, $file);
            }
        } catch (Throwable $e) {
            $this->cleanupStoredFiles($storedPaths, $post->id);

            throw $this->toOperationFailedException(
                $e,
                '添付ファイルの保存に失敗しました。',
                self::OP_STORE_FILE,
                $post->id,
            );
        }

        try {
            return DB::transaction(function () use ($post, $files, $storedPaths) {
                $attachments = [];

                foreach ($files as $index => $file) {
                    $attachments[] = $post->attachments()->create([
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $storedPaths[$index],
                        'file_type' => PostAttachmentFile::resolveType($file),
                        'file_size' => $file->getSize(),
                    ]);
                }

                return $attachments;
            });
        } catch (Throwable $e) {
            $this->cleanupStoredFiles($storedPaths, $post->id);

            throw $this->toOperationFailedException(
                $e,
                '添付ファイルの登録に失敗しました。',
                self::OP_REGISTER_ATTACHMENT,
                $post->id,
            );
        }
    }

    /**
     * $post配下の$attachmentを削除する。$attachmentが$postに属さない場合は
     * 既存と同じ404にする。この所属確認はサービス内部でも保証するため、
     * Controllerの認可チェックを経由せず直接呼び出した場合も、別投稿の
     * 添付ファイルは削除できない。認可(Gate::authorize('attach', $post))は
     * 呼び出し側(Controller)の責務であり、ここでは行わない。
     * HTTPレスポンスの組み立ては行わない。
     *
     * ファイル削除が失敗(false/例外)した場合はDBレコードを削除せず、
     * 失敗として例外を投げる。ファイル削除に成功した後にDBレコードの
     * 削除が失敗した場合も、成功扱いにせず例外を投げる(この場合ファイルは
     * 既に削除済みのため復元できない。DBレコードは残るので、後から
     * 同じ添付に対してこのメソッドを再実行すれば削除を完了できる
     * 、というのが今回の対応範囲)。
     *
     * @throws PostAttachmentOperationFailedException
     */
    public function destroy(Post $post, PostAttachment $attachment): void
    {
        if ((int) $attachment->post_id !== (int) $post->id) {
            abort(404);
        }

        try {
            $deleted = Storage::disk('public')->delete($attachment->file_path);
        } catch (Throwable $e) {
            throw $this->toOperationFailedException(
                $e,
                '添付ファイルの削除に失敗しました。',
                self::OP_DELETE_FILE,
                $post->id,
                $attachment->id,
            );
        }

        if ($deleted === false) {
            throw new PostAttachmentOperationFailedException(
                '添付ファイルの削除に失敗しました。',
                ['operation' => self::OP_DELETE_FILE, 'post_id' => $post->id, 'attachment_id' => $attachment->id],
            );
        }

        try {
            $attachment->delete();
        } catch (Throwable $e) {
            throw $this->toOperationFailedException(
                $e,
                '添付ファイルの削除記録に失敗しました。',
                self::OP_DELETE_RECORD,
                $post->id,
                $attachment->id,
            );
        }
    }

    private function storeFileOrFail(Post $post, UploadedFile $file): string
    {
        try {
            $path = $file->store("attachments/{$post->id}", 'public');
        } catch (Throwable $e) {
            throw $this->toOperationFailedException(
                $e,
                '添付ファイルの保存に失敗しました。',
                self::OP_STORE_FILE,
                $post->id,
            );
        }

        if ($path === false) {
            throw new PostAttachmentOperationFailedException(
                '添付ファイルの保存に失敗しました。',
                ['operation' => self::OP_STORE_FILE, 'post_id' => $post->id, 'file_name' => $file->getClientOriginalName()],
            );
        }

        return $path;
    }

    /**
     * 今回の呼び出しで保存済みのファイル($paths)を後片付けする。
     * 既存の添付ファイルや、別のリクエストで保存されたファイルは対象に
     * 含まれない(呼び出し元がその場で追跡したパスのみを渡すため)。
     * 1件の後片付けが失敗しても、残りの対象の後片付けを試みる。
     * 後片付け自体の失敗はreport()で記録するのみとし、呼び出し元が
     * 投げようとしている元の例外を上書きしない。
     *
     * @param  array<int, string>  $paths
     */
    private function cleanupStoredFiles(array $paths, int $postId): void
    {
        foreach ($paths as $path) {
            try {
                $deleted = Storage::disk('public')->delete($path);

                if ($deleted === false) {
                    report(new PostAttachmentOperationFailedException(
                        '添付ファイルの後片付け(削除)に失敗しました。',
                        ['operation' => self::OP_CLEANUP_FILE, 'post_id' => $postId, 'file_path' => $path],
                    ));
                }
            } catch (Throwable $e) {
                report($this->toOperationFailedException(
                    $e,
                    '添付ファイルの後片付け(削除)中に例外が発生しました。',
                    self::OP_CLEANUP_FILE,
                    $postId,
                    extraContext: ['file_path' => $path],
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $extraContext
     */
    private function toOperationFailedException(
        Throwable $e,
        string $message,
        string $operation,
        int $postId,
        ?int $attachmentId = null,
        array $extraContext = [],
    ): PostAttachmentOperationFailedException {
        if ($e instanceof PostAttachmentOperationFailedException) {
            return $e;
        }

        $context = array_filter([
            'operation' => $operation,
            'post_id' => $postId,
            'attachment_id' => $attachmentId,
        ] + $extraContext, fn ($value) => $value !== null);

        return new PostAttachmentOperationFailedException($message, $context, $e);
    }
}
