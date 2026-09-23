<?php

namespace App\Http\Controllers;

use App\Domain\Profile\QueryServices\PublicProfileQueryService;
use App\Domain\Profile\Services\PublicProfileService;
use App\Models\User;
use App\Support\PublicProfilePresenter;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class PublicProfileController extends Controller
{
    public function __construct(
        private PublicProfileService $publicProfileService,
        private PublicProfileQueryService $publicProfileQueryService,
    ) {}

    public function show(Request $request, User $user)
    {
        $viewer = $this->resolveAuthenticatedUser($request);

        $this->publicProfileService->ensureTrustScore($user);

        $data = $this->publicProfileQueryService->forDetail($user, $viewer);

        return response()->json(
            PublicProfilePresenter::detail($data['user'], $data['is_following'])
        );
    }

    private function resolveAuthenticatedUser(Request $request): ?User
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken instanceof PersonalAccessToken || ! $this->isAccessTokenValid($accessToken)) {
            return null;
        }

        $tokenable = $accessToken->tokenable;

        return $tokenable instanceof User ? $tokenable : null;
    }

    /**
     * Sanctumの認証ガード(Laravel\Sanctum\Guard::isValidAccessToken)と同じ基準で
     * トークンの有効期限を判定する。sanctum.expirationによる一律の失効と、
     * トークン単体のexpires_atによる失効の両方を考慮する。
     * (Post側のPostViewTrackingService::isAccessTokenValid()と同じ基準。
     * ドメインが異なるため実装は個別に持つ)
     */
    private function isAccessTokenValid(PersonalAccessToken $accessToken): bool
    {
        $expirationMinutes = config('sanctum.expiration');

        if ($expirationMinutes && $accessToken->created_at->lte(now()->subMinutes($expirationMinutes))) {
            return false;
        }

        return ! $accessToken->expires_at || ! $accessToken->expires_at->isPast();
    }
}
