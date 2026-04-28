<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 付箋テーブル。ユーザーが後で読み返したい投稿を保存する。
     */
    public function up(): void
    {
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id()->comment('付箋ID');
            $table->foreignId('user_id')->comment('ユーザーID')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->comment('投稿ID')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->unique(['user_id', 'post_id']);
            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
