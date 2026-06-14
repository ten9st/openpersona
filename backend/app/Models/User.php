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
            'birthdate' => 'date',
        ];
    }

    /**
     * プロフィールモデルとのリレーション
     */
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    public function profileVisibilities()
    {
        return $this->hasMany(ProfileVisibility::class);
    }

    public function educations()
    {
        return $this->hasMany(UserEducation::class)->orderBy('sort_order');
    }

    public function careers()
    {
        return $this->hasMany(UserCareer::class)->orderBy('sort_order');
    }

    public function trustScore()
    {
        return $this->hasOne(TrustScore::class);
    }

    public function identityVerifications()
    {
        return $this->hasMany(IdentityVerification::class);
    }

    public function bookmarks()
    {
        return $this->hasMany(Bookmark::class);
    }

    public function followerRecords()
    {
        return $this->hasMany(Follow::class, 'followed_user_id');
    }

    public function followingRecords()
    {
        return $this->hasMany(Follow::class, 'follower_user_id');
    }

    public function isIdentityVerified(): bool
    {
        return $this->identityVerifications()
            ->where('verification_status', IdentityVerification::STATUS_VERIFIED)
            ->exists();
    }

    public function hasLockedBasicInfo(): bool
    {
        return $this->isIdentityVerified();
    }

    /**
     * @return array{email: bool, birthdate: bool, last_name: bool, first_name: bool}
     */
    public function basicInfoLockedFields(): array
    {
        $nameLocked = $this->hasLockedBasicInfo();

        return [
            'email' => true,
            'birthdate' => true,
            'last_name' => $nameLocked,
            'first_name' => $nameLocked,
        ];
    }
}
