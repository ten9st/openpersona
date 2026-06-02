<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEducation extends Model
{
    protected $table = 'user_educations';

    protected $fillable = [
        'user_id',
        'school_name',
        'faculty',
        'degree',
        'start_year',
        'end_year',
        'is_public',
        'sort_order',
    ];

    protected $casts = [
        'start_year' => 'integer',
        'end_year' => 'integer',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
