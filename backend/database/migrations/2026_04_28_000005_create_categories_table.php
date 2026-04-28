<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 固定カテゴリテーブル。政治・社会・経済・科学・文化などを管理する。
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id()->comment('カテゴリID');
            $table->string('name')->comment('カテゴリ名');
            $table->string('slug')->unique()->comment('URL用スラッグ');
            $table->unsignedTinyInteger('posting_age_limit')->nullable()->comment('投稿可能年齢制限。政治カテゴリは18歳など');
            $table->unsignedInteger('sort_order')->default(0)->comment('表示順');
            $table->timestamps();
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
