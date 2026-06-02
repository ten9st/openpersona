<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_view_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_id')->constrained()->cascadeOnDelete()
                ->comment('投稿ID');

            $table->foreignId('personal_access_token_id')
                ->constrained('personal_access_tokens')->cascadeOnDelete()
                ->comment('閲覧時のSanctumトークンID');

            $table->timestamps();

            $table->unique(['post_id', 'personal_access_token_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_view_records');
    }
};
