<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 認証の本体テーブル。本名は必須入力とし、公開可否は profiles 側で制御する。
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id()->comment('ユーザーID');
            $table->string('email')->unique()->comment('ログイン用メールアドレス');
            $table->string('password')->comment('ログイン用パスワード（ハッシュ化して保存）');
            $table->string('last_name')->comment('姓。本名として必須入力。最低限公開される想定');
            $table->string('first_name')->comment('名。本名として必須入力。公開可否は profiles で制御');
            $table->date('birthdate')->comment('生年月日。年齢算出や年齢制限判定に利用');
            $table->timestamp('email_verified_at')->nullable()->comment('メール認証日時');
            $table->rememberToken()->comment('ログイン保持用トークン');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
