<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PublicProfilePresenter;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class PublicProfileController extends Controller
{
    public function show(Request $request, User $user)
    {
        return response()->json(
            PublicProfilePresenter::detail($user, $this->resolveAuthenticatedUser($request))
        );
    }

    private function resolveAuthenticatedUser(Request $request): ?User
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken instanceof PersonalAccessToken
            || $accessToken->tokenable_type !== User::class) {
            return null;
        }

        return $accessToken->tokenable;
    }
}
