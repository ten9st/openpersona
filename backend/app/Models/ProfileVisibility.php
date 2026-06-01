<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileVisibility extends Model
{
    public const FIELDS = [
        'last_name',
        'first_name',
        'full_name',
        'age',
        'biography',
        'occupation',
        'region',
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
            'last_name' => true,
            'first_name' => false,
            'full_name' => false,
            'age' => true,
            'biography' => false,
            'occupation' => false,
            'region' => false,
        ];
    }
}
