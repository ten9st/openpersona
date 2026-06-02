<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrustScore extends Model
{
    public const MAX_SCORE_UNVERIFIED = 50;

    public const MAX_SCORE_VERIFIED = 100;

    protected $fillable = [
        'user_id',
        'profile_score',
        'posting_score',
        'source_score',
        'history_score',
        'total_score',
        'max_score',
        'calculated_at',
    ];

    protected $casts = [
        'calculated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function ensureForUser(User $user): self
    {
        $trustScore = self::firstOrCreate(
            ['user_id' => $user->id],
            [
                'profile_score' => 0,
                'posting_score' => 0,
                'source_score' => 0,
                'history_score' => 0,
                'total_score' => 0,
                'max_score' => self::MAX_SCORE_UNVERIFIED,
            ]
        );

        self::syncMaxScore($trustScore, $user);

        return $trustScore->fresh();
    }

    public static function syncMaxScore(self $trustScore, User $user): void
    {
        $expectedMax = $user->isIdentityVerified()
            ? self::MAX_SCORE_VERIFIED
            : self::MAX_SCORE_UNVERIFIED;

        if ((int) $trustScore->max_score !== $expectedMax) {
            $trustScore->update(['max_score' => $expectedMax]);
        }
    }

    /**
     * @return array{total_score: int, max_score: int}
     */
    public function toPublicArray(): array
    {
        return [
            'total_score' => (int) $this->total_score,
            'max_score' => (int) $this->max_score,
        ];
    }
}
