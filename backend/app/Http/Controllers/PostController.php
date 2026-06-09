<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Models\Bookmark;
use App\Models\Post;
use App\Models\PostSource;
use App\Models\PostViewRecord;
use App\Models\User;
use App\Support\PostAttachmentPresenter;
use App\Support\PostListPresenter;
use App\Support\PublicProfilePresenter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

class PostController extends Controller
{
    private const VIEWED_POST_SESSION_PREFIX = 'viewed_post_';

    private const CORRECTION_TITLE_PREFIX = '【訂正】';

    public static function clearViewedPostsFromSession(Request $request): void
    {
        foreach (array_keys($request->session()->all()) as $key) {
            if (str_starts_with($key, self::VIEWED_POST_SESSION_PREFIX)) {
                $request->session()->forget($key);
            }
        }
    }

    private function resolveAccessToken(Request $request): ?PersonalAccessToken
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        return $accessToken instanceof PersonalAccessToken ? $accessToken : null;
    }

    private function hasViewedPost(Request $request, Post $post, ?PersonalAccessToken $accessToken): bool
    {
        if ($accessToken !== null) {
            return PostViewRecord::query()
                ->where('post_id', $post->id)
                ->where('personal_access_token_id', $accessToken->id)
                ->exists();
        }

        return $request->session()->has(self::VIEWED_POST_SESSION_PREFIX.$post->id);
    }

    private function isPostAuthor(Post $post, ?PersonalAccessToken $accessToken): bool
    {
        if ($accessToken === null) {
            return false;
        }

        return $accessToken->tokenable_type === User::class
            && (int) $accessToken->tokenable_id === (int) $post->user_id;
    }

    private function recordPostView(Request $request, Post $post, ?PersonalAccessToken $accessToken): void
    {
        if ($accessToken !== null) {
            PostViewRecord::create([
                'post_id' => $post->id,
                'personal_access_token_id' => $accessToken->id,
            ]);

            return;
        }

        $request->session()->put(self::VIEWED_POST_SESSION_PREFIX.$post->id, true);
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

        $query = Post::query()
            ->select(PostListPresenter::selectColumns())
            ->withCount(['bookmarks as bookmark_count'])
            ->with(PostListPresenter::eagerLoads())
            ->where('status', '!=', 'deleted')
            ->where('status', 'published')
            ->latest('published_at');

        if (! empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        if (! empty($validated['tag'])) {
            $query->whereHas('tags', fn ($tagQuery) => $tagQuery->where('slug', $validated['tag']));
        }

        $posts = $query->paginate($perPage);

        $items = collect($posts->items())
            ->map(fn (Post $post) => PostListPresenter::format($post))
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
        $posts = Post::query()
            ->select([
                'id',
                'category_id',
                'title',
                'status',
                'created_at',
                'updated_at',
            ])
            ->with('category:id,name,slug')
            ->where('user_id', $request->user()->id)
            ->where('status', 'draft')
            ->latest('updated_at')
            ->get();

        return response()->json([
            'posts' => $posts,
        ]);
    }

    public function show(Request $request, Post $post)
    {
        if ($post->status === 'deleted') {
            abort(404);
        }

        $accessToken = $this->resolveAccessToken($request);

        if ($post->status !== 'published' && ! $this->isPostAuthor($post, $accessToken)) {
            abort(404);
        }

        if ($post->status === 'published'
            && ! $this->hasViewedPost($request, $post, $accessToken)
            && ! $this->isPostAuthor($post, $accessToken)) {
            $post->increment('view_count');
            $this->recordPostView($request, $post, $accessToken);
        }

        $post->loadCount(['bookmarks as bookmark_count']);
        $post->load([
            'user:id,last_name,first_name,birthdate',
            'user.profile:id,user_id,region',
            'user.profileVisibilities' => fn ($query) => $query
                ->select(['id', 'user_id', 'field_name', 'is_public'])
                ->where('field_name', 'first_name'),
            'category:id,name,slug',
            'tags:id,name,slug',
            'sources',
            'attachments',
            'comments' => fn ($query) => $query
                ->whereHas('post', fn ($postQuery) => $postQuery->where('status', '!=', 'deleted'))
                ->select(['id', 'post_id', 'user_id', 'body', 'created_at'])
                ->with([
                    'user:id,last_name,first_name,birthdate',
                    'user.profile:id,user_id,region',
                    'user.profileVisibilities' => fn ($q) => $q
                        ->select(['id', 'user_id', 'field_name', 'is_public'])
                        ->where('field_name', 'first_name'),
                    'user.identityVerifications:id,user_id,verification_status',
                ])
                ->oldest(),
        ]);

        $postArray = $post->toArray();
        $postArray['user'] = $this->formatAuthorForList($post->user);
        $postArray['sources'] = $this->formatSources($post->sources);
        $postArray['tags'] = $this->formatTags($post->tags);
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

        $post = Post::create([
            'user_id' => $request->user()->id,
            'category_id' => $validated['category_id'],
            'title' => $validated['title'],
            'body' => $validated['body'],
            'status' => $status,
            'published_at' => $status === 'published' ? Carbon::now() : null,
        ]);

        if (array_key_exists('sources', $validated)) {
            $this->syncSources($post, $validated['sources']);
        }

        if (array_key_exists('tag_ids', $validated)) {
            $post->tags()->sync($validated['tag_ids']);
        }

        $post->load(['sources', 'tags:id,name,slug']);

        return response()->json([
            'message' => $status === 'published'
                ? '投稿を公開しました。'
                : '下書きを保存しました。',
            'post' => [
                ...$post->toArray(),
                'sources' => $this->formatSources($post->sources),
                'tags' => $this->formatTags($post->tags),
            ],
        ], 201);
    }

    public function update(UpdatePostRequest $request, Post $post)
    {
        $validated = $request->validated();

        $status = $validated['status'] ?? $post->status;
        $publishedAt = $post->published_at;

        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = Carbon::now();
        } elseif ($status === 'draft') {
            $publishedAt = null;
        }

        $post->update([
            ...array_intersect_key($validated, array_flip(['category_id', 'title', 'body'])),
            'status' => $status,
            'published_at' => $publishedAt,
        ]);

        if (array_key_exists('sources', $validated)) {
            $this->syncSources($post, $validated['sources']);
        }

        $post = $post->fresh(['sources']);

        return response()->json([
            'message' => $status === 'published'
                ? '投稿を公開しました。'
                : '下書きを保存しました。',
            'post' => [
                ...$post->toArray(),
                'sources' => $this->formatSources($post->sources),
            ],
        ]);
    }

    public function copy(Request $request, Post $post)
    {
        Gate::authorize('copy', $post);

        if ($post->status === 'deleted') {
            abort(404);
        }

        $post->load('sources');

        $copy = Post::create([
            'user_id' => $request->user()->id,
            'category_id' => $post->category_id,
            'title' => $this->correctionTitle($post->title),
            'body' => $post->body,
            'status' => 'draft',
            'published_at' => null,
        ]);

        foreach ($post->sources as $source) {
            $copy->sources()->create([
                'source_type' => $source->source_type,
                'title' => $source->title,
                'url' => $source->url,
                'note' => $source->note,
            ]);
        }

        $copy->load('sources');

        return response()->json([
            'message' => '訂正用の下書きを作成しました。内容を確認して公開してください。',
            'copied_from_post_id' => $post->id,
            'post' => [
                ...$copy->toArray(),
                'sources' => $this->formatSources($copy->sources),
            ],
        ], 201);
    }

    public function destroy(Request $request, Post $post)
    {
        Gate::authorize('delete', $post);

        $post->update(['status' => 'deleted']);

        return response()->json([
            'message' => '投稿を削除しました。',
        ]);
    }

    private function correctionTitle(string $title): string
    {
        if (str_starts_with($title, self::CORRECTION_TITLE_PREFIX)) {
            return $title;
        }

        return self::CORRECTION_TITLE_PREFIX.$title;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function syncSources(Post $post, array $sources): void
    {
        $post->sources()->delete();

        foreach ($sources as $source) {
            $post->sources()->create([
                'source_type' => $source['source_type'] ?? PostSource::TYPE_URL,
                'title' => $source['title'] ?? null,
                'url' => $source['url'] ?? null,
                'note' => $source['note'] ?? null,
            ]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PostSource>  $sources
     * @return list<array<string, mixed>>
     */
    private function formatSources($sources): array
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
     * @param  \Illuminate\Support\Collection<int, \App\Models\Tag>  $tags
     * @return list<array<string, mixed>>
     */
    private function formatTags($tags): array
    {
        return $tags->map(fn ($tag) => [
            'id' => $tag->id,
            'name' => $tag->name,
            'slug' => $tag->slug,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAuthorForList(User $user): array
    {
        return PublicProfilePresenter::summary($user);
    }
}
