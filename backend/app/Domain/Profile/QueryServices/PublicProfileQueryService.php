<?php

namespace App\Domain\Profile\QueryServices;

use App\Models\Follow;
use App\Models\User;

class PublicProfileQueryService
{
    /**
     * PublicProfilePresenter::summary()の表示に必要な関連の、プレフィックス
     * 付きEagerLoad仕様を返す。投稿一覧・投稿詳細のコメント・フォロー一覧など、
     * 複数の取得元(PostPresenter::eagerLoads()、PostQueryService::hydrateForShow()、
     * FollowController、CommentController)で同じ内容を個別に書かずに共有するための
     * ものであり、実際のクエリ実行はそれぞれの呼び出し元(取得処理)が行う。
     *
     * @return array<int|string, mixed>
     */
    public static function summaryRelations(string $prefix = ''): array
    {
        $key = fn (string $relation) => $prefix === '' ? $relation : "{$prefix}.{$relation}";

        return [
            $key('profile').':id,user_id,region',
            $key('profileVisibilities') => fn ($query) => $query
                ->select(['id', 'user_id', 'field_name', 'is_public'])
                ->where('field_name', 'first_name'),
            $key('identityVerifications').':id,user_id,verification_status',
            $key('trustScore'),
        ];
    }

    /**
     * 単一User向けにsummary表示に必要な関連データを準備する(一覧以外の
     * 単発呼び出し向け)。呼び出し元で既に読み込み済みの場合は追加のクエリを
     * 発生させない(loadMissing)。書き込みは行わない。
     */
    public function prepareSummary(User $user): User
    {
        return $user->loadMissing(self::summaryRelations());
    }

    /**
     * 公開プロフィール詳細(本人以外からの閲覧)に必要な関連データを取得する。
     * 書き込みは行わない(信頼度スコアの補完はPublicProfileServiceの責務)。
     *
     * 呼び出し元で学歴・職歴などが公開絞り込みなしに読み込み済みの可能性が
     * あるため、loadMissing()ではなくload()で必ず正しく絞り込まれた最新の
     * 状態を取得し直す(非公開情報の漏えい防止)。
     *
     * @return array{user: User, is_following: bool|null}
     */
    public function forDetail(User $user, ?User $viewer): array
    {
        $user->load([
            'profile:id,user_id,biography,occupation,region',
            'profileVisibilities:id,user_id,field_name,is_public',
            'identityVerifications:id,user_id,verification_status',
            'educations' => fn ($query) => $query
                ->where('is_public', true)
                ->orderBy('sort_order'),
            'careers' => fn ($query) => $query
                ->where('is_public', true)
                ->orderBy('sort_order'),
            'trustScore',
        ]);

        $user->loadCount([
            'followerRecords as followers_count',
            'followingRecords as following_count',
        ]);

        $isFollowing = $viewer !== null
            ? Follow::query()
                ->where('follower_user_id', $viewer->id)
                ->where('followed_user_id', $user->id)
                ->exists()
            : null;

        return [
            'user' => $user,
            'is_following' => $isFollowing,
        ];
    }
}
