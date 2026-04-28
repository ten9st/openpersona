<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 本人確認テーブル。免許証・マイナンバーカード等による確認状態を管理する。実際の書類画像や番号は原則DB保存しない。
     */
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id()->comment('本人確認ID');
            $table->foreignId('user_id')->comment('ユーザーID')->constrained()->cascadeOnDelete();
            $table->string('verification_method')->comment('本人確認方法。driver_license / my_number_card など');
            $table->string('verification_status')->default('pending')->comment('本人確認状態。pending / verified / rejected を想定');
            $table->timestamp('verified_at')->nullable()->comment('本人確認完了日時');
            $table->timestamps();
            $table->index(['user_id', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
