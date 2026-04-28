<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * フォローテーブル。ユーザー同士のフォロー関係を管理する。
     */
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id()->comment('フォローID');
            $table->foreignId('follower_user_id')->comment('フォローする側のユーザーID')->constrained('users')->cascadeOnDelete();
            $table->foreignId('followed_user_id')->comment('フォローされる側のユーザーID')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->unique(['follower_user_id', 'followed_user_id']);
            $table->index('followed_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
