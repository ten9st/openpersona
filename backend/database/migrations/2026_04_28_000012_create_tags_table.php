<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 自由タグテーブル。投稿をカテゴリより細かく分類するために利用する。
     */
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id()->comment('タグID');
            $table->string('name')->comment('タグ名');
            $table->string('slug')->unique()->comment('URL用スラッグ');
            $table->timestamps();
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
