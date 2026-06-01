## テーブル仕様

### 投稿一覧に表示するプロフィール情報

| 項目 | データソース | 表示ルール |
|------|-------------|-----------|
| 氏名（姓・名） | users.last_name / users.first_name | 姓は常に表示。名は profile_visibilities で制御 |
| 地域（都道府県） | profiles.region | 常に表示 |
| 年齢 | users.birthdate から計算 | 常に表示 |
| 信頼スコア | trust_scores.total_score | 常に表示 |

1.users
認証の本体

ポイント
・本名は必須入力
・姓・年齢は常に表示（profile_visibilities の対象外）
・名の公開は profile_visibilities で制御
・認証系の中心

users
- id
- email
- password
- last_name
- first_name
- birthdate
- created_at
- updated_at

2.profiles
公開用プロフィール

ポイント
・公開制御は profile_visibilities テーブルで一元管理
・地域（都道府県）は投稿一覧で常に表示（公開フラグの対象外）
・項目が増えてもテーブル構造を変えずに対応できる

profiles
- id
- user_id
- biography
- occupation
- region（都道府県名。例: 東京都）
- created_at
- updated_at

3.profile_visibilities
プロフィールの公開フラグ管理

ポイント
・field_name で対象フィールドを指定
・is_public で公開/非公開を管理
・項目追加時もテーブル構造を変更不要
・姓・年齢・地域は常に表示のため対象外
・first_name / biography / occupation のみ管理

profile_visibilities
- id
- user_id
- field_name
- is_public
- created_at
- updated_at

field_name の例
first_name
biography
occupation

4.user_educations
学歴

ポイント
・複数件持てる
・時系列で並べられる
・公開/非公開を個別管理できる

user_educations
- id
- user_id
- school_name
- faculty
- degree
- start_year
- end_year
- is_public
- sort_order
- created_at
- updated_at

5.user_careers
職歴

ポイント
・現職と過去履歴を両方表現できる
・OpenPersonaらしい「履歴型プロフィール」の核

user_careers
- id
- user_id
- company_name
- position
- start_year
- end_year
- is_current
- is_public
- sort_order
- created_at
- updated_at

6.posts
長文投稿の本体

ポイント
・titleは必須
・bodyは長文前提
・閲覧数と付箋数は保持してよい
・いいね欄は不要

posts
- id
- user_id
- category_id
- title
- body
- view_count
- bookmark_count
- status
- published_at
- created_at
- updated_at

statusの例: draft / published / deleted

7.post_sources
参考資料のURLの管理

ポイント
・1投稿に複数ソースを持てる
・「信頼できるソースの情報を公開する仕組み」の核

post_sources
- id
- post_id
- source_type
- title
- url
- note
- created_at
- updated_at

source_typeの例: url / book / paper / goverment_document / other

8.post_attachments
添付ファイル用

ポイント
・実ファイルはストレージ保存
・DBにはパスだけ持つ
・PDFや画像を想定

post_attachments
- id
- post_id
- file_name
- file_path
- file_type
- file_size
- created_at

9.comments
コメントはフラット

ポイント
・親コメントIDは持たない
・コメント表示時にユーザーの公開情報と信頼度を一緒に出す

comments
- id
- post_id
- user_id
- body
- created_at
- updated_at

10.bookmarks
付箋

ポイント
・user_id + post_id にユニーク制約
・posts.bookmark_count はここから集計、または保存更新

bookmarks
- id
- user_id
- post_id
- created_at

11.follows
フォロー

ポイント
・同一ユーザー同士を禁止
・follower_user_id + followed_user_id にユニーク制約

follows
- id
- follower_user_id
- followed_user_id
- created_at

12.categories
固定カテゴリ

例
政治
社会
経済
科学
文化

ポイント
・政治カテゴリだけ posting_age_limit = 18 にできる

categories
- id
- name
- slug
- posting_age_limit
- sort_order
- created_at
- updated_at

13.tags
自由タグ

tags
- id
- name
- slug
- created_at
- updated_at

中間テーブル
post_tags
- id
- post_id
- tag_id

14.trust_scores
信頼スコア本体
最初はシンプルに、内訳も持っておくと良い

ポイント
・合計だけでなく内訳も保存
・後でロジックを変えても追いやすい

trust_scores
- id
- user_id
- profile_score
- posting_score
- source_score
- history_score
- total_score
- calculated_at
- created_at
- updated_at

15.identity_verifications
本人確認

identity_verifications
- id
- user_id
- verification_method
- verification_status
- verified_at
- created_at
- updated_at

verification_method の例
driver_license
my_number_card

verification_status の例
pending
verified
rejected

## ER
users 1 - 1 profiles
users 1 - n profile_visibilities
users 1 - n user_educations
users 1 - n user_careers
users 1 - n posts
users 1 - n comments
users 1 - n bookmarks
users 1 - n follows (follower側)
users 1 - 1 trust_scores
users 1 - n identity_verifications

posts 1 - n comments
posts 1 - n post_sources
posts 1 - n post_attachments
posts n - 1 categories
posts n - n tags
