<?php

namespace App\Domain\Post\Models;

use Illuminate\Database\Eloquent\Model;

class PostViewRecord extends Model
{
    protected $fillable = [
        'post_id',
        'personal_access_token_id',
    ];
}
