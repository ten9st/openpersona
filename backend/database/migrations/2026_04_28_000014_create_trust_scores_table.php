<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 信頼スコアテーブル。プロフィール・投稿・ソース・履歴の内訳を保持し、合計スコアを管理する。
     */
    public function up(): void
    {
        Schema::create('trust_scores', function (Blueprint $table) {
            $table->id()->comment('信頼スコアID');
            $table->foreignId('user_id')->comment('ユーザーID')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('profile_score')->default(0)->comment('プロフィール公開度によるスコア');
            $table->unsignedInteger('posting_score')->default(0)->comment('投稿品質によるスコア');
            $table->unsignedInteger('source_score')->default(0)->comment('情報ソースの有無・質によるスコア');
            $table->unsignedInteger('history_score')->default(0)->comment('履歴・一貫性によるスコア');
            $table->unsignedInteger('total_score')->default(0)->comment('総合信頼スコア');
            $table->timestamp('calculated_at')->nullable()->comment('スコア計算日時');
            $table->timestamps();
            $table->unique('user_id');
            $table->index('total_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_scores');
    }
};
