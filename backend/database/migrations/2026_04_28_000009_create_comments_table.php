<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * コメントテーブル。コメントは階層化せず、フラット構造で管理する。
     */
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id()->comment('コメントID');
            $table->foreignId('post_id')->comment('投稿ID')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->comment('コメント投稿者ユーザーID')->constrained()->cascadeOnDelete();
            $table->text('body')->comment('コメント本文');
            $table->timestamps();
            $table->index('post_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
