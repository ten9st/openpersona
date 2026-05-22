<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * 一括代入許可
     */
    protected $fillable = [
        'email',
        'password',
        'last_name',
        'first_name',
        'birthdate',
    ];

    /**
     * JSON出力時に隠す
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Cast定義
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * プロフィールモデルとのリレーション
     */
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }
}
