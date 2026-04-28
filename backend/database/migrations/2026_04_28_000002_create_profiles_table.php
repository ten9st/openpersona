<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 公開用プロフィールテーブル。本人情報と公開情報を分離し、プライバシーと透明性を両立する。
     */
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id()->comment('プロフィールID');
            $table->foreignId('user_id')->comment('ユーザーID')->constrained()->cascadeOnDelete();
            $table->string('display_last_name')->comment('公開表示用の姓');
            $table->string('display_first_name')->nullable()->comment('公開表示用の名。氏名公開時に使用');
            $table->boolean('age_public')->default(true)->comment('年齢を公開するかどうか');
            $table->boolean('full_name_public')->default(false)->comment('氏名を公開するかどうか。falseの場合は姓のみ公開');
            $table->text('biography')->nullable()->comment('自己紹介文');
            $table->string('occupation')->nullable()->comment('職業');
            $table->boolean('occupation_public')->default(false)->comment('職業を公開するかどうか');
            $table->string('region')->nullable()->comment('居住地域');
            $table->boolean('region_public')->default(false)->comment('居住地域を公開するかどうか');
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
