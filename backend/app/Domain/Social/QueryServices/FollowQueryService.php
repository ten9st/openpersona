<?php

namespace App\Domain\Social\QueryServices;

use App\Domain\Profile\QueryServices\PublicProfileQueryService;
use App\Models\Follow;
use App\Models\User;
use Illuminate\Support\Collection;

class FollowQueryService
{
    /**
     * $userのフォロワー一覧を作成日時の新しい順で取得する。
     * PublicProfilePresenter::summary()の整形に必要な関連を事前取得済みで
     * 返すため、整形中に追加のDB問い合わせは発生しない。書き込みは行わない。
     *
     * @return Collection<int, User>
     */
    public function followers(User $user): Collection
    {
        return Follow::query()
            ->where('followed_user_id', $user->id)
            ->with(['follower' => fn ($query) => $query->with(PublicProfileQueryService::summaryRelations())])
            ->latest()
            ->get()
            ->map(fn (Follow $follow) => $follow->follower)
            ->values();
    }

    /**
     * $userのフォロー一覧を作成日時の新しい順で取得する。
     * PublicProfilePresenter::summary()の整形に必要な関連を事前取得済みで
     * 返すため、整形中に追加のDB問い合わせは発生しない。書き込みは行わない。
     *
     * @return Collection<int, User>
     */
    public function following(User $user): Collection
    {
        return Follow::query()
            ->where('follower_user_id', $user->id)
            ->with(['followed' => fn ($query) => $query->with(PublicProfileQueryService::summaryRelations())])
            ->latest()
            ->get()
            ->map(fn (Follow $follow) => $follow->followed)
            ->values();
    }

    public function followersCount(User $user): int
    {
        return Follow::query()
            ->where('followed_user_id', $user->id)
            ->count();
    }

    public function followingCount(User $user): int
    {
        return Follow::query()
            ->where('follower_user_id', $user->id)
            ->count();
    }

    /**
     * タイムライン表示用に、$userがフォローしているユーザーIDの一覧を取得する。
     *
     * @return Collection<int, int>
     */
    public function followedUserIds(User $user): Collection
    {
        return Follow::query()
            ->where('follower_user_id', $user->id)
            ->pluck('followed_user_id');
    }
}
