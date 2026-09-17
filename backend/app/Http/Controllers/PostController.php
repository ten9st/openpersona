<?php

namespace App\Http\Controllers;

use App\Domain\Post\Models\Bookmark;
use App\Domain\Post\Models\Post;
use App\Domain\Post\Presenters\PostAttachmentPresenter;
use App\Domain\Post\Presenters\PostPresenter;
use App\Domain\Post\QueryServices\PostQueryService;
use App\Domain\Post\Services\PostService;
use App\Domain\Post\Services\PostViewTrackingService;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Models\User;
use App\Support\PublicProfilePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PostController extends Controller
{
    public function __construct(
        private PostQueryService $postQueryService,
        private PostService $postService,
        private PostViewTrackingService $postViewTrackingService,
    ) {}

    public static function clearViewedPostsFromSession(Request $request): void
    {
        PostViewTrackingService::clearViewedPostsFromSession($request);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'tag' => ['nullable', 'string', 'exists:tags,slug'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $perPage = $validated['per_page'] ?? 20;

        $posts = $this->postQueryService->paginatePublished(
            $validated['category_id'] ?? null,
            $validated['tag'] ?? null,
            $perPage,
        );

        $items = collect($posts->items())
            ->map(fn (Post $post) => PostPresenter::format($post))
            ->all();

        return response()->json([
            'posts' => $items,
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    public function drafts(Request $request)
    {
        $posts = $this->postQueryService->draftsForUser($request->user()->id);

        return response()->json([
            'posts' => $posts,
        ]);
    }

    public function show(Request $request, Post $post)
    {
        if ($post->status === 'deleted') {
            abort(404);
        }

        $accessToken = $this->postViewTrackingService->resolveAccessToken($request);

        if ($post->status !== 'published' && ! $this->postViewTrackingService->isPostAuthor($post, $accessToken)) {
            abort(404);
        }

        $this->postViewTrackingService->registerViewIfNeeded($request, $post, $accessToken);

        $this->postQueryService->hydrateForShow($post);

        $postArray = $post->toArray();
        $postArray['user'] = PublicProfilePresenter::summary($post->user);
        $postArray['sources'] = PostPresenter::sources($post->sources);
        $postArray['tags'] = PostPresenter::tags($post->tags);
        $postArray['attachments'] = $post->attachments
            ->map(fn ($attachment) => PostAttachmentPresenter::format($attachment))
            ->values()
            ->all();
        $postArray['comments'] = collect($post->comments)->map(function ($comment) {
            $commentArray = $comment->toArray();
            $commentArray['user'] = PublicProfilePresenter::summary($comment->user);

            return $commentArray;
        })->all();

        if ($accessToken !== null
            && $accessToken->tokenable_type === User::class) {
            $postArray['is_bookmarked'] = Bookmark::query()
                ->where('user_id', $accessToken->tokenable_id)
                ->where('post_id', $post->id)
                ->exists();
        }

        return response()->json([
            'post' => $postArray,
        ]);
    }

    public function store(StorePostRequest $request)
    {
        $validated = $request->validated();
        $status = $validated['status'] ?? 'draft';

        $post = $this->postService->store($request->user(), $validated);

        return response()->json([
            'message' => $status === 'published'
                ? '投稿を公開しました。'
                : '下書きを保存しました。',
            'post' => [
                ...$post->toArray(),
                'sources' => PostPresenter::sources($post->sources),
                'tags' => PostPresenter::tags($post->tags),
            ],
        ], 201);
    }

    public function update(UpdatePostRequest $request, Post $post)
    {
        $validated = $request->validated();
        $status = $validated['status'] ?? $post->status;

        $post = $this->postService->update($post, $validated);

        return response()->json([
            'message' => $status === 'published'
                ? '投稿を公開しました。'
                : '下書きを保存しました。',
            'post' => [
                ...$post->toArray(),
                'sources' => PostPresenter::sources($post->sources),
            ],
        ]);
    }

    public function copy(Request $request, Post $post)
    {
        Gate::authorize('copy', $post);

        if ($post->status === 'deleted') {
            abort(404);
        }

        $copy = $this->postService->copy($request->user(), $post);

        return response()->json([
            'message' => '訂正用の下書きを作成しました。内容を確認して公開してください。',
            'copied_from_post_id' => $post->id,
            'post' => [
                ...$copy->toArray(),
                'sources' => PostPresenter::sources($copy->sources),
            ],
        ], 201);
    }

    public function destroy(Request $request, Post $post)
    {
        Gate::authorize('delete', $post);

        $this->postService->destroy($post);

        return response()->json([
            'message' => '投稿を削除しました。',
        ]);
    }
}
