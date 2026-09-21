<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\PostAttachmentController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

// ============================================================
// 認証不要のAPI
// ============================================================
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/posts', [PostController::class, 'index']);
Route::get('/tags', [TagController::class, 'index']);
Route::get('/users/{user}', [PublicProfileController::class, 'show']);

// 新規ユーザー登録
Route::post('/register', [AuthController::class, 'register']);

// /posts/{post} (下記webグループ内) より先に登録しないと
// "drafts" が {post} のワイルドカードに吸収され404になるため、
// このルートだけ先に登録する。
Route::middleware('auth:sanctum')->get('/posts/drafts', [PostController::class, 'drafts']);

// ============================================================
// webミドルウェアを使用するAPI
// セッションCookieが必要な処理
// ============================================================
Route::middleware('web')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    // 閲覧数カウントのセッション管理のためwebミドルウェアを使用
    Route::get('/posts/{post}', [PostController::class, 'show']);
});

// ============================================================
// 認証必須なAPI (Sanctumトークンを使用)
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    // 認証ユーザー情報
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // プロフィール
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    // 投稿
    // (/posts/drafts は /posts/{post} との衝突を避けるため上部で登録済み)
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
