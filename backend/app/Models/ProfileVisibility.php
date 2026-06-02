<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileVisibility extends Model
{
    public const FIELDS = [
        'first_name',
        'biography',
        'occupation',
    ];

    protected $fillable = [
        'user_id',
        'field_name',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, bool>
     */
    public static function defaultMap(): array
    {
        return [
            'first_name' => false,
            'biography' => false,
            'occupation' => false,
        ];
    }
}
