<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Models\Profile;

Route::post('/register', function (Request $request) {
    $validated = $request->validate([
        'email' => ['required', 'email', 'unique:users,email'],
        'password' => ['required', 'min:8'],
        'last_name' => ['required', 'string', 'max:255'],
        'first_name' => ['required', 'string', 'max:255'],
        'birthdate' => ['required', 'date'],
    ]);

    $user = User::create([
        'email' => $validated['email'],
        'password' => Hash::make($validated['password']),
        'last_name' => $validated['last_name'],
        'first_name' => $validated['first_name'],
        'birthdate' => $validated['birthdate'],
    ]);

    Profile::create([
        'user_id' => $user->id,
        'display_last_name' => $user->last_name,
        'display_first_name' => $user->first_name,
        'age_public' => true,
        'full_name_public' => false,
    ]);

    return response()->json([
        'message' => 'ユーザー登録が完了しました。',
        'user' => $user,
    ], 201);
});

Route::middleware('web')->post('/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
    ]);

    if (!Auth::attempt($credentials)) {
        return response()->json([
            'message' => 'メールアドレスまたはパスワードが違います。',
        ], 401);
    }

    PostController::clearViewedPostsFromSession($request);

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
});

Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    return response()->json([
        'user' => $request->user(),
    ]);
});

Route::get('/posts', [PostController::class, 'index']);
Route::middleware('web')->get('/posts/{post}', [PostController::class, 'show']);
Route::middleware('auth:sanctum')->post('/posts', [PostController::class, 'store']);
Route::middleware('auth:sanctum')->post('/posts/{post}/comments', [CommentController::class, 'store']);

Route::middleware('auth:sanctum')->get('/profile', [ProfileController::class, 'show']);
Route::middleware('auth:sanctum')->put('/profile', [ProfileController::class, 'update']);