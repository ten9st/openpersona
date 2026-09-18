<?php

namespace App\Domain\Post\QueryServices;

use App\Domain\Post\Models\Post;
use App\Domain\Post\Presenters\PostPresenter;
use App\Domain\Profile\QueryServices\PublicProfileQueryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PostQueryService
{
    public function paginatePublished(?int $categoryId, ?string $tagSlug, int $perPage): LengthAwarePaginator
    {
        $query = Post::query()
            ->select(PostPresenter::selectColumns())
            ->withCount(['bookmarks as bookmark_count'])
            ->with(PostPresenter::eagerLoads())
            ->where('status', '!=', 'deleted')
            ->where('status', 'published')
            ->latest('published_at');

        if (! empty($categoryId)) {
            $query->where('category_id', $categoryId);
        }

        if (! empty($tagSlug)) {
            $query->whereHas('tags', fn ($tagQuery) => $tagQuery->where('slug', $tagSlug));
        }

        return $query->paginate($perPage);
    }

    /**
     * @return Collection<int, Post>
     */
    public function draftsForUser(int $userId): Collection
    {
        return Post::query()
            ->select([
                'id',
                'category_id',
                'title',
                'status',
                'created_at',
                'updated_at',
            ])
            ->with('category:id,name,slug')
            ->where('user_id', $userId)
            ->where('status', 'draft')
            ->latest('updated_at')
            ->get();
    }

    public function hydrateForShow(Post $post): Post
    {
        $post->loadCount(['bookmarks as bookmark_count']);
        $post->load([
            'user:id,last_name,first_name,birthdate',
            ...PublicProfileQueryService::summaryRelations('user'),
            'category:id,name,slug',
            'tags:id,name,slug',
            'sources',
            'attachments',
            'comments' => fn ($query) => $query
                ->whereHas('post', fn ($postQuery) => $postQuery->where('status', '!=', 'deleted'))
                ->select(['id', 'post_id', 'user_id', 'body', 'created_at'])
                ->with([
                    'user:id,last_name,first_name,birthdate',
                    ...PublicProfileQueryService::summaryRelations('user'),
                ])
                ->oldest(),
        ]);

        return $post;
    }

    /**
     * @param  iterable<int>  $userIds
     * @return Collection<int, Post>
     */
    public function publishedByUserIds(iterable $userIds): Collection
    {
        return Post::query()
            ->select(PostPresenter::selectColumns())
            ->withCount(['bookmarks as bookmark_count'])
            ->with(PostPresenter::eagerLoads())
            ->whereIn('user_id', $userIds)
            ->where('status', 'published')
            ->latest('published_at')
            ->get();
    }
}
