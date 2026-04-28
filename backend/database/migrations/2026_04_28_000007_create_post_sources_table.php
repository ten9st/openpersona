<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 投稿に紐づく参考資料・情報ソーステーブル。信頼できるソースを公開する仕組みの核となる。
     */
    public function up(): void
    {
        Schema::create('post_sources', function (Blueprint $table) {
            $table->id()->comment('投稿ソースID');
            $table->foreignId('post_id')->comment('投稿ID')->constrained()->cascadeOnDelete();
            $table->string('source_type')->default('url')->comment('ソース種別。url / book / paper / government_document / other を想定');
            $table->string('title')->nullable()->comment('参考資料のタイトル');
            $table->text('url')->nullable()->comment('参考URL');
            $table->text('note')->nullable()->comment('補足説明');
            $table->timestamps();
            $table->index('post_id');
            $table->index('source_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_sources');
    }
};
