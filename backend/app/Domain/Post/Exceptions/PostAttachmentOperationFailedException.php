<?php

namespace App\Domain\Post\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 添付ファイルの保存・削除処理(ファイル操作またはDB登録・削除)が
 * 失敗したことを表す。呼び出し側(Controller)はこれを捕捉し、利用者には
 * 内部の例外詳細を含まない汎用的なエラー応答を返す。
 */
class PostAttachmentOperationFailedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  ログに残す調査用の情報
     *                                         (post_id/attachment_id/operationなど)。
     *                                         トークンやファイル内容などの機密情報は含めない。
     */
    public function __construct(
        string $message,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Laravelの例外ハンドラが report() 時に自動的に読み取り、
     * ログへマージする(Illuminate\Foundation\Exceptions\Handler::exceptionContext())。
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
