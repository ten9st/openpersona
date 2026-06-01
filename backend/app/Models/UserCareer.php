<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCareer extends Model
{
    protected $table = 'user_careers';

    protected $fillable = [
        'user_id',
        'company_name',
        'position',
        'start_year',
        'end_year',
        'is_current',
        'is_public',
        'sort_order',
    ];

    protected $casts = [
        'start_year' => 'integer',
        'end_year' => 'integer',
        'is_current' => 'boolean',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
