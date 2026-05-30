<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    protected $fillable = [
        'user_id',
        'display_last_name',
        'display_first_name',
        'age_public',
        'full_name_public',
        'biography',
        'occupation',
        'occupation_public',
        'region',
        'region_public',
    ];

    protected $casts = [
        'age_public' => 'boolean',
        'full_name_public' => 'boolean',
        'occupation_public' => 'boolean',
        'region_public' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}