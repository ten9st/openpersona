<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostSource extends Model
{
    public const TYPE_URL = 'url';

    public const TYPE_BOOK = 'book';

    public const TYPE_PAPER = 'paper';

    public const TYPE_GOVERNMENT_DOCUMENT = 'government_document';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_URL,
        self::TYPE_BOOK,
        self::TYPE_PAPER,
        self::TYPE_GOVERNMENT_DOCUMENT,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'post_id',
        'source_type',
        'title',
        'url',
        'note',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
