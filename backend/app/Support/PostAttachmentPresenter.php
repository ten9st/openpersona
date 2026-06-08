<?php

namespace App\Support;

use App\Models\PostAttachment;
use Illuminate\Support\Facades\Storage;

class PostAttachmentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function format(PostAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'file_name' => $attachment->file_name,
            'file_type' => $attachment->file_type,
            'file_size' => (int) $attachment->file_size,
            'url' => self::publicUrl($attachment->file_path),
            'created_at' => $attachment->created_at,
        ];
    }

    private static function publicUrl(string $filePath): string
    {
        $url = Storage::disk('public')->url($filePath);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').$url;
    }
}
