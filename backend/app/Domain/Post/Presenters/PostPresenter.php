<?php

namespace App\Domain\Post\Presenters;

use App\Domain\Post\Models\Post;
use App\Domain\Post\Models\PostSource;
use App\Domain\Post\Models\Tag;
use App\Support\PublicProfilePresenter;
use Illuminate\Support\Collection;

class PostPresenter
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

    /**
     * @param  Collection<int, PostSource>  $sources
     * @return list<array<string, mixed>>
     */
    public static function sources($sources): array
    {
        return $sources->map(fn (PostSource $source) => [
            'id' => $source->id,
            'source_type' => $source->source_type,
            'title' => $source->title,
            'url' => $source->url,
            'note' => $source->note,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Tag>  $tags
     * @return list<array<string, mixed>>
     */
    public static function tags($tags): array
    {
        return $tags->map(fn (Tag $tag) => [
            'id' => $tag->id,
            'name' => $tag->name,
            'slug' => $tag->slug,
        ])->values()->all();
    }
}
