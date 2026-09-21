<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Services\AuthService;
use App\Domain\Post\Services\PostViewTrackingService;
use App\Models\User;
use App\Support\UserBasicInfoRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function register(Request $request, AuthService $authService)
    {
        $request->merge(UserBasicInfoRules::trimInput($request->all()));

        $validated = $request->validate([
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            ...UserBasicInfoRules::userRules(),
        ], UserBasicInfoRules::messages());

        $user = $authService->register($validated);

        return response()->json([
            'message' => 'ユーザー登録が完了しました。',
            'user' => $user,
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'メールアドレスまたはパスワードが違います。',
            ], 401);
        }

        PostViewTrackingService::clearViewedPostsFromSession($request);

        $user = User::where('email', $credentials['email'])->firstOrFail();
        $token = $user->createToken('openpersona_token')->plainTextToken;

        return response()->json([
            'message' => 'ログインが成功しました。',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'last_name' => $user->last_name,
                'first_name' => $user->first_name,
                'birthdate' => $user->birthdate,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'ログアウトしました。',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }
}
