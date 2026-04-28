<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 投稿添付ファイルテーブル。実ファイルはストレージ保存し、DBにはパスやメタ情報のみを保持する。
     */
    public function up(): void
    {
        Schema::create('post_attachments', function (Blueprint $table) {
            $table->id()->comment('添付ファイルID');
            $table->foreignId('post_id')->comment('投稿ID')->constrained()->cascadeOnDelete();
            $table->string('file_name')->comment('元ファイル名');
            $table->string('file_path')->comment('ストレージ上の保存パス');
            $table->string('file_type')->nullable()->comment('ファイル種別・MIMEタイプ');
            $table->unsignedBigInteger('file_size')->nullable()->comment('ファイルサイズ（バイト）');
            $table->timestamp('created_at')->nullable()->comment('作成日時');
            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_attachments');
    }
};
