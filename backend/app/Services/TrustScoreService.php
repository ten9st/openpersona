<?php

namespace App\Services;

use App\Models\Post;
use App\Models\TrustScore;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TrustScoreService
{
    public function calculate(User $user): void
    {
        $profileScore = $this->calcProfileScore($user);
        $postingScore = $this->calcPostingScore($user);
        $sourceScore = $this->calcSourceScore($user);
        $historyScore = $this->calcHistoryScore($user);

        $maxScore = $user->isIdentityVerified()
            ? TrustScore::MAX_SCORE_VERIFIED
            : TrustScore::MAX_SCORE_UNVERIFIED;

        $totalScore = min(
            $profileScore + $postingScore + $sourceScore + $historyScore,
            $maxScore
        );

        TrustScore::updateOrCreate(
            ['user_id' => $user->id],
            [
                'profile_score' => $profileScore,
                'posting_score' => $postingScore,
                'source_score' => $sourceScore,
                'history_score' => $historyScore,
                'total_score' => $totalScore,
                'max_score' => $maxScore,
                'calculated_at' => now(),
            ]
        );
    }

    public function calcProfileScore(User $user): int
    {
        $user->loadMissing(['profile', 'educations', 'careers']);
        $profile = $user->profile;
        $points = config('trust_score.profile', []);
        $score = 0;

        if ($profile !== null && filled($profile->occupation)) {
            $score += (int) ($points['has_occupation'] ?? 0);
        }

        if ($profile !== null && filled($profile->region)) {
            $score += (int) ($points['has_region'] ?? 0);
        }

        if ($profile !== null && filled($profile->biography)) {
            $score += (int) ($points['has_biography'] ?? 0);
        }

        if ($user->educations->isNotEmpty()) {
            $score += (int) ($points['has_education'] ?? 0);
        }

        if ($user->careers->isNotEmpty()) {
            $score += (int) ($points['has_career'] ?? 0);
        }

        return $score;
    }

    public function calcPostingScore(User $user): int
    {
        $publishedCount = Post::query()
            ->where('user_id', $user->id)
            ->where('status', 'published')
            ->count();

        return $this->scoreFromThresholdConfig($publishedCount, config('trust_score.posting', []));
    }

    public function calcSourceScore(User $user): int
    {
        $publishedQuery = Post::query()
            ->where('user_id', $user->id)
            ->where('status', 'published');

        $totalPublished = (clone $publishedQuery)->count();

        if ($totalPublished === 0) {
            return 0;
        }

        $withSources = (clone $publishedQuery)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('post_sources')
                    ->whereColumn('post_sources.post_id', 'posts.id');
            })
            ->count();

        $rate = (int) round(($withSources / $totalPublished) * 100);

        return $this->scoreFromThresholdConfig($rate, config('trust_score.source', []));
    }

    public function calcHistoryScore(User $user): int
    {
        $days = (int) $user->created_at->diffInDays(now());

        return $this->scoreFromThresholdConfig($days, config('trust_score.history', []));
    }

    /**
     * @param  array<string, int>  $config
     */
    private function scoreFromThresholdConfig(int $actual, array $config): int
    {
        $score = 0;

        foreach ($config as $key => $points) {
            $threshold = (int) substr(strrchr($key, '_') ?: '', 1);

            if ($actual >= $threshold) {
                $score += (int) $points;
            }
        }

        return $score;
    }
}
