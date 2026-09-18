<?php

namespace App\Domain\Post\Services;

use App\Domain\Post\Models\Post;
use App\Domain\Post\Models\PostViewRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class PostViewTrackingService
{
    private const VIEWED_POST_SESSION_PREFIX = 'viewed_post_';

    public static function clearViewedPostsFromSession(Request $request): void
    {
        foreach (array_keys($request->session()->all()) as $key) {
            if (str_starts_with($key, self::VIEWED_POST_SESSION_PREFIX)) {
                $request->session()->forget($key);
            }
        }
    }

    public function resolveAccessToken(Request $request): ?PersonalAccessToken
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken instanceof PersonalAccessToken || ! $this->isAccessTokenValid($accessToken)) {
            return null;
        }

        return $accessToken;
    }

    /**
     * Sanctumの認証ガード(Laravel\Sanctum\Guard::isValidAccessToken)と同じ基準で
     * トークンの有効期限を判定する。sanctum.expirationによる一律の失効と、
     * トークン単体のexpires_atによる失効の両方を考慮する。
     */
    private function isAccessTokenValid(PersonalAccessToken $accessToken): bool
    {
        $expirationMinutes = config('sanctum.expiration');

        if ($expirationMinutes && $accessToken->created_at->lte(now()->subMinutes($expirationMinutes))) {
            return false;
        }

        return ! $accessToken->expires_at || ! $accessToken->expires_at->isPast();
    }

    public function isPostAuthor(Post $post, ?PersonalAccessToken $accessToken): bool
    {
        if ($accessToken === null) {
            return false;
        }

        return $accessToken->tokenable_type === User::class
            && (int) $accessToken->tokenable_id === (int) $post->user_id;
    }

    public function registerViewIfNeeded(Request $request, Post $post, ?PersonalAccessToken $accessToken): void
    {
        if ($post->status !== 'published'
            || $this->isPostAuthor($post, $accessToken)
            || $this->hasViewedPost($request, $post, $accessToken)) {
            return;
        }

        $post->increment('view_count');
        $this->recordPostView($request, $post, $accessToken);
    }

    private function hasViewedPost(Request $request, Post $post, ?PersonalAccessToken $accessToken): bool
    {
        if ($accessToken !== null) {
            return PostViewRecord::query()
                ->where('post_id', $post->id)
                ->where('personal_access_token_id', $accessToken->id)
                ->exists();
        }

        return $request->session()->has(self::VIEWED_POST_SESSION_PREFIX.$post->id);
    }

    private function recordPostView(Request $request, Post $post, ?PersonalAccessToken $accessToken): void
    {
        if ($accessToken !== null) {
            PostViewRecord::create([
                'post_id' => $post->id,
                'personal_access_token_id' => $accessToken->id,
            ]);

            return;
        }

        $request->session()->put(self::VIEWED_POST_SESSION_PREFIX.$post->id, true);
    }
}
