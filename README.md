# OpenPersona

## 概要

OpenPersona は、個人が自身の情報を選択的に公開し、その透明性によって情報の信頼性を高めることを目的としたプラットフォームです。

従来の匿名型 SNS とは異なり、「誰が発信しているか」を重視し、投稿内容と発信者の情報を結びつけることで、信頼できる情報基盤の構築を目指します。

---

## コンセプト

- 匿名ではなく「責任のある発信」
- 公開情報と信頼性の紐付け
- 信頼性の可視化
- 長文・論理的な情報共有

---

## 解決したい課題

現在の SNS には以下の課題があります。

- 匿名性により情報の信頼性が低い
- 誰が発信しているか不明確
- 評価が「いいね」などの単純指標に依存している

---

## 解決手段

OpenPersona では以下を組み合わせて解決します。

- ユーザーの公開プロフィール情報
- 投稿内容
- 情報ソース（参考文献など）
- 履歴（過去の発言・経歴）

これらを統合し、「情報の信頼性」を判断可能にする仕組みを提供します。

---

## システムの特徴

- 公開情報と投稿を紐付ける SNS 構造
- 投稿の信頼性をユーザー情報に基づいて評価
- 履歴情報を含めた一貫性の可視化
- 情報ソース付き投稿

---

## 想定ユースケース

- 政治・政策議論
- 専門知識の共有
- 社会問題の議論
- 研究・考察の発信

---

## 技術スタック

| レイヤー | 技術 | バージョン |
|----------|------|------------|
| Frontend | Next.js (App Router) | 16.x |
| Frontend | React | 19.x |
| Frontend | Tailwind CSS | 4.x |
| Backend | Laravel | ^13.7 |
| Backend | PHP | ^8.3 |
| 認証 | Laravel Sanctum | ^4.0 |
| Database | PostgreSQL（本番・Docker 想定） | 16 |
| Database | SQLite（ローカル開発のデフォルト） | — |
| Infrastructure | Docker Compose | — |
| テスト | PHPUnit | 12 |

---

## ディレクトリ構成

```
openpersona/
├── frontend/          # Next.js フロントエンド（:3000）
│   ├── app/           # ページ（App Router）
│   ├── components/    # UI コンポーネント
│   └── lib/           # API・投稿ソース・コピー等のユーティリティ
├── backend/           # Laravel API サーバー（:8000）
│   ├── app/
│   │   ├── Http/Controllers/   # Post, Comment, Profile, PublicProfile, Category
│   │   ├── Http/Requests/      # StorePostRequest, UpdatePostRequest
│   │   ├── Models/             # User, Profile, Post, PostSource, TrustScore 等
│   │   ├── Policies/           # PostPolicy
│   │   ├── Services/           # TrustScoreService
│   │   └── Observers/          # Post, PostSource, Profile, User
│   ├── database/migrations/    # スキーマ定義
│   ├── routes/api.php          # API ルート
│   └── tests/Feature/          # 機能テスト
├── infra/             # Docker 設定
│   ├── docker-compose.yml
│   ├── backend/Dockerfile
│   └── frontend/Dockerfile
└── docs/              # 設計メモ（db_design.md 等）
```

---

## 実装状況

### 実装済み（MVP）

| 機能 | Backend API | Frontend |
|------|:-----------:|:--------:|
| ユーザー登録（本名必須） | ✅ | ✅ |
| ログイン（Sanctum トークン） | ✅ | ✅ |
| ログアウト（トークン revoke） | ✅ | ✅ |
| プロフィールの取得・更新 | ✅ | ✅ |
| プロフィール公開設定（項目単位） | ✅ | ✅ |
| 学歴・職歴の登録・公開設定 | ✅ | ✅ |
| 他人の公開プロフィール閲覧 | ✅ | ✅ |
| 信頼スコアの算出・表示 | ✅ | ✅ |
| カテゴリ一覧 | ✅ | ✅ |
| 投稿一覧（公開済みのみ） | ✅ | ✅ |
| 投稿詳細（ゲスト閲覧可） | ✅ | ✅ |
| 投稿作成（下書き / 公開） | ✅ | ✅ |
| 投稿ソース（参考文献）の登録・表示 | ✅ | ✅ |
| 下書き一覧・下書きの編集 | ✅ | ✅ |
| 公開済み投稿のコピー（訂正用下書き作成） | ✅ | ✅ |
| 投稿の削除（論理削除、投稿者のみ） | ✅ | ✅ |
| コメント投稿・表示 | ✅ | ✅ |
| 閲覧数カウント（重複排除） | ✅ | ✅ |
| 本人確認済みバッジ表示 | ✅ | ✅ |

### DB スキーマのみ（API・UI 未実装）

| 機能 | テーブル |
|------|----------|
| 付箋（ブックマーク） | `bookmarks` |
| フォロー | `follows` |
| 投稿添付ファイル | `post_attachments` |
| タグ | `tags`, `post_tags` |
| 本人確認（申請・審査フロー） | `identity_verifications`（`verified` バッジ表示のみ実装） |
| カテゴリ CRUD（管理画面） | `categories`（一覧 API・Seeder 投入は実装済み） |

詳細なテーブル定義は [`docs/db_design.md`](docs/db_design.md) を参照。

---

## アーキテクチャ

```
┌─────────────────┐     HTTP (JSON)      ┌─────────────────┐
│  Next.js        │ ───────────────────► │  Laravel API    │
│  localhost:3000 │  Bearer Token        │  localhost:8000 │
│                 │  + Session Cookie    │                 │
└─────────────────┘                      └────────┬────────┘
                                                  │
                                                  ▼
                                         ┌─────────────────┐
                                         │  PostgreSQL /   │
                                         │  SQLite         │
                                         └─────────────────┘
```

- フロントエンドは `http://localhost:8000/api` を直接呼び出す
- ログイン時は Sanctum の CSRF Cookie + セッション Cookie を使用
- 認証済み API は `Authorization: Bearer {token}` ヘッダーを付与
- トークンはフロントの `localStorage.openpersona_token` に保存

---

## 認証

### 方式

- **Laravel Sanctum Personal Access Token**: ログイン成功時に `openpersona_token` 名で発行
- **SPA セッション**: ログイン・投稿詳細取得時に `web` ミドルウェア経由でセッション Cookie を利用
- **CSRF**: ログイン前に `GET /sanctum/csrf-cookie` を呼び出し、`X-XSRF-TOKEN` ヘッダーを付与

### 登録時の副作用

- `users` レコード作成（本名・生年月日は必須）
- `profiles` レコードを自動作成
- `profile_visibilities` をデフォルト値で作成（`first_name` / `biography` / `occupation` は非公開）

### 認可

- `PostPolicy` により投稿の更新・削除・コピーを制御
  - **更新**: 投稿者本人かつ `status = draft` のみ
  - **コピー**: 投稿者本人かつ `status !== deleted`
  - **削除**: 投稿者本人

### 未実装

- メール認証

---

## API 仕様

すべての API ルートには `/api` プレフィックスが付きます。

### 認証・ユーザー

| メソッド | パス | 認証 | 説明 |
|----------|------|:----:|------|
| `POST` | `/api/register` | 不要 | 新規登録 |
| `POST` | `/api/login` | 不要 | ログイン（セッション + トークン発行） |
| `POST` | `/api/logout` | 必須 | ログアウト（現在のトークンを revoke） |
| `GET` | `/api/me` | 必須 | 認証ユーザー取得 |

#### `POST /api/register`

**リクエスト:**
```json
{
  "email": "user@example.com",
  "password": "password123",
  "last_name": "山田",
  "first_name": "太郎",
  "birthdate": "1990-01-01"
}
```

**レスポンス `201`:**
```json
{
  "message": "ユーザー登録が完了しました。",
  "user": { "id": 1, "email": "...", "last_name": "...", "first_name": "...", "birthdate": "..." }
}
```

#### `POST /api/login`

**リクエスト:**
```json
{ "email": "user@example.com", "password": "password123" }
```

**レスポンス `200`:**
```json
{
  "message": "ログインが成功しました。",
  "token": "<sanctum plain text token>",
  "user": { "id": 1, "email": "...", "last_name": "...", "first_name": "...", "birthdate": "..." }
}
```

**副作用:** セッション上の閲覧済み投稿記録をクリア

#### `POST /api/logout`

**レスポンス `200`:**
```json
{ "message": "ログアウトしました。" }
```

**副作用:** リクエストに付与された Personal Access Token を `personal_access_tokens` から削除

---

### カテゴリ

| メソッド | パス | 認証 | 説明 |
|----------|------|:----:|------|
| `GET` | `/api/categories` | 不要 | カテゴリ一覧（`sort_order` 昇順） |

---

### 投稿

| メソッド | パス | 認証 | 説明 |
|----------|------|:----:|------|
| `GET` | `/api/posts` | 不要 | 公開投稿一覧（ページネーション） |
| `GET` | `/api/posts/drafts` | 必須 | 自分の下書き一覧 |
| `GET` | `/api/posts/{post}` | 任意 | 投稿詳細（コメント・ソース含む） |
| `POST` | `/api/posts` | 必須 | 投稿作成 |
| `PUT` | `/api/posts/{post}` | 必須 | 下書きの更新・公開 |
| `POST` | `/api/posts/{post}/copy` | 必須 | 訂正用に投稿を下書きコピー |
| `DELETE` | `/api/posts/{post}` | 必須 | 投稿の論理削除 |

#### `GET /api/posts`

**クエリパラメータ:**

| パラメータ | 型 | デフォルト | 説明 |
|------------|-----|-----------|------|
| `category_id` | integer | — | カテゴリで絞り込み |
| `page` | integer | 1 | ページ番号 |
| `per_page` | integer | 20 | 1 ページあたり件数（最大 50） |

**条件:** `status = published` のみ、`published_at` 降順

**レスポンス `200`:**
```json
{
  "posts": [
    {
      "id": 1,
      "user_id": 1,
      "category_id": 1,
      "title": "タイトル",
      "view_count": 10,
      "bookmark_count": 0,
      "published_at": "2026-06-01T00:00:00.000000Z",
      "user": { "id": 1, "last_name": "山田", "first_name": "太郎" },
      "category": { "id": 1, "name": "政治", "slug": "politics" }
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```

※ 一覧では `body` は含まれない

#### `GET /api/posts/{post}`

**閲覧条件:**

| `status` | ゲスト | 投稿者（Bearer） |
|----------|--------|----------------|
| `published` | 閲覧可 | 閲覧可 |
| `draft` | **404** | 閲覧可 |
| `deleted` | **404** | **404** |

**レスポンス `200`:**
```json
{
  "post": {
    "id": 1,
    "title": "...", "body": "...",
    "view_count": 10, "bookmark_count": 0,
    "user": { "id": 1, "last_name": "...", "first_name": "...", "trust_score": { "total_score": 0, "max_score": 50 } },
    "category": { "id": 1, "name": "...", "slug": "..." },
    "sources": [
      { "id": 1, "source_type": "url", "title": "参考記事", "url": "https://...", "note": null }
    ],
    "comments": [
      {
        "id": 1, "post_id": 1, "user_id": 2, "body": "...",
        "created_at": "...",
        "user": { "id": 2, "last_name": "...", "first_name": "..." }
      }
    ]
  }
}
```

コメントは `created_at` 昇順。公開済み投稿の閲覧時に `view_count` を加算（[閲覧数カウント仕様](#閲覧数カウント仕様) 参照）。

#### `POST /api/posts`

**リクエスト:**
```json
{
  "category_id": 1,
  "title": "タイトル",
  "body": "本文",
  "status": "published",
  "sources": [
    {
      "source_type": "url",
      "title": "参考記事",
      "url": "https://example.com/article",
      "note": "一次情報"
    }
  ]
}
```

| フィールド | 必須 | 説明 |
|------------|:----:|------|
| `category_id` | ✅ | 存在するカテゴリ ID |
| `title` | ✅ | 最大 255 文字 |
| `body` | ✅ | 本文 |
| `status` | — | `draft` または `published`（省略時: `draft`） |
| `sources` | — | 参考文献の配列（省略可） |
| `sources.*.source_type` | ✅※ | `url` / `book` / `paper` / `government_document` / `other` |
| `sources.*.title` | — | タイトル |
| `sources.*.url` | — | URL |
| `sources.*.note` | — | 補足 |

※ `sources` を送る場合は各要素に必須

`status = published` の場合、`published_at` に現在日時を設定。

#### `PUT /api/posts/{post}`

**条件:** 投稿者本人かつ `status = draft` のみ（公開済みは **403**）

`POST /api/posts` と同様のフィールド。`sources` を送った場合は全件差し替え（空配列で全削除）。

#### `POST /api/posts/{post}/copy`

**条件:** 投稿者本人かつ `status !== deleted`

公開済み・下書きを複製し、**新しい下書き**を作成する。タイトル先頭に `【訂正】` を付与（既にある場合はそのまま）。本文・カテゴリ・`post_sources` をコピー。元投稿は変更しない。

**レスポンス `201`:**
```json
{
  "message": "訂正用の下書きを作成しました。内容を確認して公開してください。",
  "copied_from_post_id": 1,
  "post": { "id": 2, "status": "draft", "title": "【訂正】...", "sources": [] }
}
```

#### `DELETE /api/posts/{post}`

**条件:** 投稿者本人

**副作用:** `status` を `deleted` に更新（物理削除ではない）

---

### コメント

| メソッド | パス | 認証 | 説明 |
|----------|------|:----:|------|
| `POST` | `/api/posts/{post}/comments` | 必須 | コメント作成 |

**リクエスト:**
```json
{ "body": "コメント本文" }
```

**条件:** 投稿が `published` でない場合 **404**

**レスポンス `201`:**
```json
{
  "message": "コメントを投稿しました。",
  "comment": { "id": 1, "body": "...", "user": { ... } }
}
```

コメントはフラット構造（親子なし）。削除 API は未実装。

---

### プロフィール

| メソッド | パス | 認証 | 説明 |
|----------|------|:----:|------|
| `GET` | `/api/profile` | 必須 | プロフィール取得（なければ自動作成） |
| `PUT` | `/api/profile` | 必須 | プロフィール更新 |
| `GET` | `/api/users/{user}` | 不要 | 他人の公開プロフィール取得 |

**PUT リクエスト（抜粋）:**
```json
{
  "last_name": "山田",
  "first_name": "太郎",
  "birthdate": "1990-01-01",
  "biography": "自己紹介文",
  "occupation": "エンジニア",
  "region": "東京都",
  "visibilities": {
    "first_name": false,
    "biography": true,
    "occupation": true
  },
  "educations": [
    {
      "school_name": "東京大学",
      "faculty": "工学部",
      "degree": "学士",
      "start_year": 2010,
      "end_year": 2014,
      "is_public": true
    }
  ],
  "careers": [
    {
      "company_name": "A社",
      "position": "エンジニア",
      "start_year": 2015,
      "end_year": null,
      "is_current": true,
      "is_public": false
    }
  ]
}
```

本人確認済みユーザーは姓・名・生年月日・メールアドレスの変更不可（`meta.basic_info_locked: true`）。

---

### ヘルスチェック

| メソッド | パス | 説明 |
|----------|------|------|
| `GET` | `/api/health` | API 稼働確認 |
| `GET` | `/up` | Laravel 組み込みヘルスチェック |
| `GET` | `/sanctum/csrf-cookie` | CSRF Cookie 取得（SPA ログイン用） |

---

## 閲覧数カウント仕様

投稿詳細取得時に `view_count` をインクリメントする。以下の条件で重複を排除する。

| 条件 | 動作 |
|------|------|
| 投稿者本人（Bearer トークンのユーザー = 投稿 `user_id`） | カウントしない |
| ゲスト（未認証） | セッションキー `viewed_post_{id}` で 1 回のみ |
| 認証済み | `post_view_records` テーブル（`post_id` + `personal_access_token_id` でユニーク） |
| ログイン時 | セッション上の閲覧記録をクリア（トークン単位の記録は保持） |

---

## フロントエンド

### ページ一覧

| パス | 認証 | 説明 |
|------|:----:|------|
| `/` | 不要 | トップ（各機能へのリンク） |
| `/register` | 不要 | 新規登録 |
| `/login` | 不要 | ログイン |
| `/posts` | 不要（閲覧） | 投稿一覧（ゲストも閲覧可） |
| `/posts/[id]` | 不要（閲覧） | 投稿詳細・コメント・参考文献表示 |
| `/posts/create` | 必須 | 投稿作成（カテゴリ選択・参考文献入力） |
| `/posts/drafts` | 必須 | 下書き一覧 |
| `/posts/[id]/edit` | 必須 | 下書き編集（公開済みはコピー導線のみ） |
| `/users/[id]` | 不要 | 公開プロフィール閲覧 |
| `/profile` | 必須 | プロフィール編集 |

### ゲスト / ログイン済みの挙動

- **ゲスト**: 投稿一覧・詳細・公開プロフィールの閲覧が可能。コメント・投稿作成はログインを促す
- **ログイン済み**: 投稿作成、下書き編集、コメント投稿、プロフィール編集が可能
- **ログアウト**: `POST /api/logout` でトークン revoke 後、`localStorage` からトークンを削除（投稿一覧ページから操作可能）

### 投稿の編集ポリシー（フロント）

- **下書き**: `/posts/[id]/edit` で編集・公開
- **公開済み**: 直接編集不可。「コピーして訂正投稿を作成」で `【訂正】` 付き下書きを作成し、編集後に新規公開

### API 呼び出し

| ページ | 使用 API |
|--------|----------|
| `/register` | `POST /api/register` |
| `/login` | `GET /sanctum/csrf-cookie` → `POST /api/login` |
| `/posts` | `GET /api/posts`、`POST /api/logout` |
| `/posts/[id]` | `GET /api/posts/{id}`（`credentials: 'include'`）、`POST .../comments`、`POST .../copy`、`DELETE ...` |
| `/posts/create` | `GET /api/categories` → `POST /api/posts` |
| `/posts/drafts` | `GET /api/posts/drafts` |
| `/posts/[id]/edit` | `GET /api/posts/{id}` → `PUT /api/posts/{id}` |
| `/users/[id]` | `GET /api/users/{id}` |
| `/profile` | `GET` / `PUT /api/profile` |

---

## データモデル

### Eloquent モデル（実装済み）

```
users 1──1 profiles
users 1──1 trust_scores
users 1──n posts, comments, user_educations, user_careers
users 1──n profile_visibilities, identity_verifications

posts n──1 categories
posts 1──n comments, post_sources, post_view_records
```

| モデル | テーブル | 主要リレーション |
|--------|----------|------------------|
| `User` | `users` | `hasOne(Profile)`, `hasOne(TrustScore)`, `hasMany(Post)`, `HasApiTokens` |
| `Profile` | `profiles` | `belongsTo(User)` |
| `ProfileVisibility` | `profile_visibilities` | `belongsTo(User)` |
| `UserEducation` | `user_educations` | `belongsTo(User)` |
| `UserCareer` | `user_careers` | `belongsTo(User)` |
| `TrustScore` | `trust_scores` | `belongsTo(User)` |
| `IdentityVerification` | `identity_verifications` | `belongsTo(User)` |
| `Post` | `posts` | `belongsTo(User)`, `belongsTo(Category)`, `hasMany(Comment)`, `hasMany(PostSource)` |
| `PostSource` | `post_sources` | `belongsTo(Post)` |
| `Comment` | `comments` | `belongsTo(Post)`, `belongsTo(User)` |
| `Category` | `categories` | — |
| `PostViewRecord` | `post_view_records` | — |

### `users` 主要カラム

`id`, `email` (unique), `password`, `last_name`, `first_name`, `birthdate`, `email_verified_at`, timestamps

### `posts.status`

| 値 | 説明 |
|----|------|
| `draft` | 下書き（投稿者のみ閲覧・編集可） |
| `published` | 公開（編集不可。コピーで訂正用下書きを作成） |
| `deleted` | 論理削除（一覧・詳細から非表示） |

### 信頼スコア（`TrustScoreService`）

`profile_score` + `posting_score` + `source_score` + `history_score` の合計を `total_score` として保持。上限は本人確認前 50・後 100。算出ロジックは `config/trust_score.php` を参照。

`source_score` は公開投稿のうち `post_sources` を 1 件以上持つ割合（25% / 50% / 75% 閾値）で加点。

### 将来テーブル（ER 概要）

```
users 1──n bookmarks, follows (follower / followed)

posts 1──n post_attachments
posts n──n tags (post_tags)
```

詳細は [`docs/db_design.md`](docs/db_design.md) を参照。

---

## 開発環境の起動

### Docker（推奨）

```bash
cd infra
docker compose up -d
```

| サービス | URL / ポート |
|----------|--------------|
| Frontend | http://localhost:3000 |
| Backend | http://localhost:8000 |
| PostgreSQL | localhost:5432（DB: `openpersona`, user/pass: `openpersona` / `secret`） |

**初回セットアップ:**

`docker compose up` 時に backend が自動で以下を実行します。

- `.env` が無ければ `backend/.env.docker.example` をコピーして `APP_KEY` を生成
- `php artisan migrate --force`

初回のみカテゴリ等を投入:

```bash
docker exec -it openpersona_backend php artisan db:seed
```

手動で入る場合:

```bash
docker exec -it openpersona_backend sh
cp .env.docker.example .env   # 未作成時のみ
php artisan key:generate        # APP_KEY が空のときのみ
php artisan migrate
php artisan db:seed
```

### ローカル（Composer）

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # SQLite 利用時
php artisan migrate
composer run dev                 # serve + queue + pail + vite
```

```bash
cd frontend
npm install
npm run dev
```

### テスト

```bash
cd backend
composer test
```

Feature テスト: `AuthTest`, `PostTest`, `CommentTest`, `ProfileTest`, `PublicProfileTest`, `CategoryTest`, `TrustScoreSourceTest` など（計 53 件）

---

## 環境変数

### Backend（`.env.example` 主要項目）

| 変数 | デフォルト | 用途 |
|------|------------|------|
| `DB_CONNECTION` | `sqlite` | DB 接続種別（Docker は `backend/.env.docker.example` 参照） |
| `SESSION_DRIVER` | `database` | セッション保存 |
| `SESSION_DOMAIN` | `localhost` | Cookie ドメイン |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:3000,...` | CORS 許可オリジン |
| `SANCTUM_STATEFUL_DOMAINS` | localhost 系 | SPA 認証ドメイン |

---

## DB 更新の方針

- 認証まわり：Eloquent のまま
- 一覧取得：Query Builder でも OK
- 大量更新：Query Builder / 生 SQL
- 特殊な重い処理：生 SQL

---

## 既知の制限・未対応事項

1. **ログアウト UI** — 投稿一覧ページのみ。プロフィール等の全画面共通ナビは未整備
2. **本人確認** — `identity_verifications` の `verified` バッジ表示のみ。申請・審査フローは未実装
3. **カテゴリ管理** — 一覧 API・Seeder 投入はあるが、管理画面による CRUD は未実装
4. コメント削除、付箋・フォロー、投稿添付・タグは未実装
5. 訂正投稿と元投稿の紐付け（`copied_from_post_id` は API レスポンスのみ。DB カラムは未保持）
