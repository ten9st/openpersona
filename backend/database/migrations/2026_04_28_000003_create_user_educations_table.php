<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 学歴テーブル。複数の学歴を時系列で保持し、公開・非公開を個別管理する。
     */
    public function up(): void
    {
        Schema::create('user_educations', function (Blueprint $table) {
            $table->id()->comment('学歴ID');
            $table->foreignId('user_id')->comment('ユーザーID')->constrained()->cascadeOnDelete();
            $table->string('school_name')->comment('学校名');
            $table->string('faculty')->nullable()->comment('学部・学科');
            $table->string('degree')->nullable()->comment('学位・課程');
            $table->unsignedSmallInteger('start_year')->nullable()->comment('開始年');
            $table->unsignedSmallInteger('end_year')->nullable()->comment('終了年');
            $table->boolean('is_public')->default(false)->comment('公開するかどうか');
            $table->unsignedInteger('sort_order')->default(0)->comment('表示順');
            $table->timestamps();
            $table->index(['user_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_educations');
    }
};
