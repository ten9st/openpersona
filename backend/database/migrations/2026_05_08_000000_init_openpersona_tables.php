<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== profiles（公開プロフィール） =====
        Schema::create('profiles', function (Blueprint $table) {
            $table->id()->comment('プロフィールID');

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('ユーザーID');

            $table->text('biography')->nullable()->comment('自己紹介');
            $table->string('occupation')->nullable()->comment('職業');
            $table->string('region')->nullable()->comment('地域');

            $table->timestamps();
        });

        // ===== profile_visibilities（公開フラグ） =====
        Schema::create('profile_visibilities', function (Blueprint $table) {
            $table->id()->comment('公開設定ID');

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('ユーザーID');

            $table->string('field_name')->comment('対象フィールド名');
            $table->boolean('is_public')->default(false)->comment('公開フラグ');

            $table->timestamps();

            $table->unique(['user_id', 'field_name']);
        });

        // ===== categories（カテゴリ） =====
        Schema::create('categories', function (Blueprint $table) {
            $table->id()->comment('カテゴリID');
            $table->string('name')->comment('カテゴリ名');
            $table->string('slug')->unique()->comment('URL用スラッグ');

            $table->unsignedTinyInteger('posting_age_limit')->nullable()
                ->comment('投稿可能年齢（例：政治は18歳）');

            $table->unsignedInteger('sort_order')->default(0)
                ->comment('表示順');

            $table->timestamps();
        });

        // ===== posts（投稿） =====
        Schema::create('posts', function (Blueprint $table) {
            $table->id()->comment('投稿ID');

            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('投稿者ユーザーID');

            $table->foreignId('category_id')->constrained()
                ->comment('カテゴリID');

            $table->string('title')->comment('タイトル');
            $table->longText('body')->comment('本文');

            $table->unsignedBigInteger('view_count')->default(0)
                ->comment('閲覧数');

            $table->unsignedBigInteger('bookmark_count')->default(0)
                ->comment('付箋数');

            $table->string('status')->default('draft')
                ->comment('状態（draft/published/deleted）');

            $table->timestamp('published_at')->nullable()
                ->comment('公開日時');

            $table->timestamps();
        });

        // ===== comments（コメント） =====
        Schema::create('comments', function (Blueprint $table) {
            $table->id()->comment('コメントID');

            $table->foreignId('post_id')->constrained()->cascadeOnDelete()
                ->comment('投稿ID');

            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('ユーザーID');

            $table->text('body')->comment('コメント本文');

            $table->timestamps();
        });

        // ===== bookmarks（付箋） =====
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id()->comment('付箋ID');

            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('ユーザーID');

            $table->foreignId('post_id')->constrained()->cascadeOnDelete()
                ->comment('投稿ID');

            $table->timestamps();

            $table->unique(['user_id', 'post_id']);
        });

        // ===== follows（フォロー） =====
        Schema::create('follows', function (Blueprint $table) {
            $table->id()->comment('フォローID');

            $table->foreignId('follower_user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('フォローする側');

            $table->foreignId('followed_user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('フォローされる側');

            $table->timestamps();

            $table->unique(['follower_user_id', 'followed_user_id']);
        });

        // ===== trust_scores（信頼スコア） =====
        Schema::create('trust_scores', function (Blueprint $table) {
            $table->id()->comment('信頼スコアID');

            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('ユーザーID');

            $table->unsignedInteger('profile_score')->default(0)
                ->comment('プロフィールスコア');

            $table->unsignedInteger('posting_score')->default(0)
                ->comment('投稿スコア');

            $table->unsignedInteger('source_score')->default(0)
                ->comment('ソーススコア');

            $table->unsignedInteger('history_score')->default(0)
                ->comment('履歴スコア');

            $table->unsignedInteger('total_score')->default(0)
                ->comment('合計スコア');

            $table->timestamp('calculated_at')->nullable()
                ->comment('計算日時');

            $table->timestamps();

            $table->unique('user_id');
        });

        // ===== post_sources（参考情報） =====
        Schema::create('post_sources', function (Blueprint $table) {
            $table->id()->comment('ソースID');

            $table->foreignId('post_id')->constrained()->cascadeOnDelete()
                ->comment('投稿ID');

            $table->string('source_type')->default('url')
                ->comment('種別');

            $table->string('title')->nullable()->comment('タイトル');
            $table->text('url')->nullable()->comment('URL');
            $table->text('note')->nullable()->comment('補足');

            $table->timestamps();
        });

        // ===== post_attachments（添付） =====
        Schema::create('post_attachments', function (Blueprint $table) {
            $table->id()->comment('添付ID');

            $table->foreignId('post_id')->constrained()->cascadeOnDelete()
                ->comment('投稿ID');

            $table->string('file_name')->comment('ファイル名');
            $table->string('file_path')->comment('保存パス');
            $table->string('file_type')->nullable()->comment('ファイル種別');
            $table->unsignedBigInteger('file_size')->nullable()->comment('サイズ');

            $table->timestamp('created_at')->nullable()->comment('作成日時');
        });

        // ===== tags =====
        Schema::create('tags', function (Blueprint $table) {
            $table->id()->comment('タグID');
            $table->string('name')->comment('タグ名');
            $table->string('slug')->unique()->comment('スラッグ');
            $table->timestamps();
        });

        // ===== post_tags =====
        Schema::create('post_tags', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->unique(['post_id', 'tag_id']);
        });

        // ===== user_educations =====
        Schema::create('user_educations', function (Blueprint $table) {
            $table->id()->comment('学歴ID');

            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('ユーザーID');

            $table->string('school_name')->comment('学校名');
            $table->string('faculty')->nullable()->comment('学部');
            $table->string('degree')->nullable()->comment('学位');

            $table->unsignedSmallInteger('start_year')->nullable()->comment('開始年');
            $table->unsignedSmallInteger('end_year')->nullable()->comment('終了年');

            $table->boolean('is_public')->default(false)->comment('公開フラグ');
            $table->unsignedInteger('sort_order')->default(0)->comment('順序');

            $table->timestamps();
        });

        // ===== user_careers =====
        Schema::create('user_careers', function (Blueprint $table) {
            $table->id()->comment('職歴ID');

            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('ユーザーID');

            $table->string('company_name')->comment('会社名');
            $table->string('position')->nullable()->comment('役職');

            $table->unsignedSmallInteger('start_year')->nullable()->comment('開始年');
            $table->unsignedSmallInteger('end_year')->nullable()->comment('終了年');

            $table->boolean('is_current')->default(false)->comment('現職フラグ');
            $table->boolean('is_public')->default(false)->comment('公開フラグ');

            $table->unsignedInteger('sort_order')->default(0)->comment('順序');

            $table->timestamps();
        });

        // ===== identity_verifications =====
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id()->comment('本人確認ID');

            $table->foreignId('user_id')->constrained()->cascadeOnDelete()
                ->comment('ユーザーID');

            $table->string('verification_method')->comment('確認方法');
            $table->string('verification_status')->default('pending')
                ->comment('状態');

            $table->timestamp('verified_at')->nullable()
                ->comment('確認日時');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
        Schema::dropIfExists('user_careers');
        Schema::dropIfExists('user_educations');
        Schema::dropIfExists('post_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('post_attachments');
        Schema::dropIfExists('post_sources');
        Schema::dropIfExists('trust_scores');
        Schema::dropIfExists('follows');
        Schema::dropIfExists('bookmarks');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('profile_visibilities');
        Schema::dropIfExists('profiles');
    }
};