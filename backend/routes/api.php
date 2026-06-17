<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\PostAttachmentController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\TagController;
use App\Models\Profile;
use App\Models\ProfileVisibility;
use App\Models\User;
use App\Support\UserBasicInfoRules;

// ============================================================
// 認証不要のAPI
// ============================================================
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/posts', [PostController::class, 'index']);
Route::get('/tags', [TagController::class, 'index']);
Route::get('/users/{user}', [PublicProfileController::class, 'show']);

// 新規ユーザー登録
Route::post('/register', function (Request $request) {
    $request->merge(UserBasicInfoRules::trimInput($request->all()));

    $validated = $request->validate([
        'email' => ['required', 'email', 'unique:users,email'],
        'password' => ['required', 'min:8'],
        ...UserBasicInfoRules::userRules(),
    ], UserBasicInfoRules::messages());

    $user = User::create([
        'email' => $validated['email'],
        'password' => Hash::make($validated['password']),
        'last_name' => $validated['last_name'],
        'first_name' => $validated['first_name'],
        'birthdate' => $validated['birthdate'],
    ]);

    Profile::create(['user_id' => $user->id]);

    foreach (ProfileVisibility::defaultMap() as $fieldName => $isPublic) {
        ProfileVisibility::create([
            'user_id' => $user->id,
            'field_name' => $fieldName,
            'is_public' => $isPublic,
        ]);
    }

    return response()->json([
        'message' => 'ユーザー登録が完了しました。',
        'user' => $user,
    ], 201);
});

// ============================================================
// webミドルウェアを使用するAPI
// セッションCookieが必要な処理
// ============================================================
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

// 閲覧数カウントのセッション管理のためwebミドルウェアを使用
Route::middleware('web')->get('/posts/{post}', [PostController::class, 'show']);

// ============================================================
// 認証必須なAPI (Sanctumトークンを使用)
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    // 認証ユーザー情報
    Route::get('/me', function (Request $request) {
        return response()->json([
            'user' => $request->user(),
        ]);
    });
    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json([
            'message' => 'ログアウトしました。',
        ]);
    });

    // プロフィール
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    // 投稿
    Route::get('/posts/drafts', [PostController::class, 'drafts']);
    Route::post('/posts', [PostController::class, 'store']);
    Route::post('/posts/{post}/copy', [PostController::class, 'copy']);
    Route::put('/posts/{post}', [PostController::class, 'update']);
    Route::delete('/posts/{post}', [PostController::class, 'destroy']);

    // 添付ファイル
    Route::post('/posts/{post}/attachments', [PostAttachmentController::class, 'store']);
    Route::delete('/posts/{post}/attachments/{attachment}', [PostAttachmentController::class, 'destroy']);

    // 付箋
    Route::get('/bookmarks', [BookmarkController::class, 'index']);
    Route::post('/posts/{post}/bookmark', [BookmarkController::class, 'store']);
    Route::delete('/posts/{post}/bookmark', [BookmarkController::class, 'destroy']);

    // コメント
    Route::post('/posts/{post}/comments', [CommentController::class, 'store']);

    // タグ
    Route::post('/tags', [TagController::class, 'store']);

    // フォロー・タイムライン
    Route::get('/timeline', [FollowController::class, 'timeline']);
    Route::get('/users/{user}/followers', [FollowController::class, 'followers']);
    Route::get('/users/{user}/following', [FollowController::class, 'following']);
    Route::post('/users/{user}/follow', [FollowController::class, 'store']);
    Route::delete('/users/{user}/follow', [FollowController::class, 'destroy']);
});