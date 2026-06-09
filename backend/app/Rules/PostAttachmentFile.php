<?php

namespace App\Rules;

use App\Models\PostAttachment;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class PostAttachmentFile implements ValidationRule
{
    private const IMAGE_MAX_BYTES = 10 * 1024 * 1024;

    private const PDF_MAX_BYTES = 20 * 1024 * 1024;

    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('ファイルのアップロードに失敗しました。');

            return;
        }

        $type = self::resolveType($value);

        if ($type === PostAttachment::TYPE_IMAGE) {
            if ($value->getSize() > self::IMAGE_MAX_BYTES) {
                $fail('画像ファイルは10MB以下にしてください。');
            }

            return;
        }

        if ($type === PostAttachment::TYPE_PDF) {
            if ($value->getSize() > self::PDF_MAX_BYTES) {
                $fail('PDFファイルは20MB以下にしてください。');
            }

            return;
        }

        $fail('jpg / png / gif / webp / pdf のみアップロードできます。');
    }

    public static function resolveType(UploadedFile $file): ?string
    {
        $mime = (string) $file->getMimeType();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($mime, self::IMAGE_MIMES, true)
            || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return PostAttachment::TYPE_IMAGE;
        }

        if ($mime === 'application/pdf' || $extension === 'pdf') {
            return PostAttachment::TYPE_PDF;
        }

        return null;
    }
}
