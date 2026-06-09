<?php

namespace App\Support;

use App\Models\Post;

class PostListPresenter
{
    /**
     * @return array<int, string>
     */
    public static function eagerLoads(): array
    {
        return [
            'user:id,last_name,first_name,birthdate',
            'user.profile:id,user_id,region',
            'user.profileVisibilities' => fn ($query) => $query
                ->select(['id', 'user_id', 'field_name', 'is_public'])
                ->where('field_name', 'first_name'),
            'category:id,name,slug',
            'tags:id,name,slug',
        ];
    }

    /**
     * @return list<string>
     */
    public static function selectColumns(): array
    {
        return [
            'id',
            'user_id',
            'category_id',
            'title',
            'view_count',
            'published_at',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function format(Post $post, ?bool $isBookmarked = null): array
    {
        $postArray = $post->toArray();
        $postArray['user'] = PublicProfilePresenter::summary($post->user);

        if ($isBookmarked !== null) {
            $postArray['is_bookmarked'] = $isBookmarked;
        }

        return $postArray;
    }
}
