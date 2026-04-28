<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 職歴テーブル。現職・過去職歴を保持し、履歴型プロフィールの核とする。
     */
    public function up(): void
    {
        Schema::create('user_careers', function (Blueprint $table) {
            $table->id()->comment('職歴ID');
            $table->foreignId('user_id')->comment('ユーザーID')->constrained()->cascadeOnDelete();
            $table->string('company_name')->comment('会社名・組織名');
            $table->string('position')->nullable()->comment('役職・職種');
            $table->unsignedSmallInteger('start_year')->nullable()->comment('開始年');
            $table->unsignedSmallInteger('end_year')->nullable()->comment('終了年');
            $table->boolean('is_current')->default(false)->comment('現在の職歴かどうか');
            $table->boolean('is_public')->default(false)->comment('公開するかどうか');
            $table->unsignedInteger('sort_order')->default(0)->comment('表示順');
            $table->timestamps();
            $table->index(['user_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_careers');
    }
};
