<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 長文投稿テーブル。OpenPersonaの中心となる記事型投稿を管理する。
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id()->comment('投稿ID');
            $table->foreignId('user_id')->comment('投稿者ユーザーID')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->comment('カテゴリID')->constrained();
            $table->string('title')->comment('投稿タイトル。必須');
            $table->longText('body')->comment('投稿本文。長文・論文的な内容を想定');
            $table->unsignedBigInteger('view_count')->default(0)->comment('閲覧数');
            $table->unsignedBigInteger('bookmark_count')->default(0)->comment('付箋登録数');
            $table->string('status')->default('draft')->comment('投稿状態。draft / published / deleted を想定');
            $table->timestamp('published_at')->nullable()->comment('公開日時');
            $table->timestamps();
            $table->index(['status', 'published_at']);
            $table->index('category_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
