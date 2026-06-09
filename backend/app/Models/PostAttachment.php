<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostAttachment extends Model
{
    public const TYPE_IMAGE = 'image';

    public const TYPE_PDF = 'pdf';

    public $timestamps = false;

    protected $fillable = [
        'post_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PostAttachment $attachment) {
            $attachment->created_at = $attachment->created_at ?? now();
        });
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
