<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OpenPersona ユーザーテーブル
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id()->comment('ユーザーID');

            $table->string('email')
                ->unique()
                ->comment('メールアドレス');

            $table->string('password')
                ->comment('パスワード');

            $table->string('last_name')
                ->comment('姓');

            $table->string('first_name')
                ->comment('名');

            $table->date('birthdate')
                ->comment('生年月日');

            $table->timestamp('email_verified_at')
                ->nullable()
                ->comment('メール認証日時');

            $table->rememberToken();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};