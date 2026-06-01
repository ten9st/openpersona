<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostViewRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class PostController extends Controller
{
    private const VIEWED_POST_SESSION_PREFIX = 'viewed_post_';

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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $perPage = $validated['per_page'] ?? 20;

        $query = Post::query()
            ->select([
                'id',
                'user_id',
                'category_id',
                'title',
                'view_count',
                'bookmark_count',
                'published_at',
                'created_at',
                'updated_at',
            ])
            ->with([
                'user:id,last_name,first_name',
                'category:id,name,slug',
            ])
            ->where('status', 'published')
            ->latest('published_at');

        if (! empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        $posts = $query->paginate($perPage);

        return response()->json([
            'posts' => $posts->items(),
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

        $post->load([
            'user:id,last_name,first_name',
            'category:id,name,slug',
            'comments' => fn ($query) => $query
                ->select(['id', 'post_id', 'user_id', 'body', 'created_at'])
                ->with('user:id,last_name,first_name')
                ->oldest(),
        ]);

        return response()->json([
            'post' => $post,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $status = $validated['status'] ?? 'draft';

        $post = Post::create([
            'user_id' => $request->user()->id,
            'category_id' => $validated['category_id'],
            'title' => $validated['title'],
            'body' => $validated['body'],
            'status' => $status,
            'published_at' => $status === 'published' ? Carbon::now() : null,
        ]);

        return response()->json([
            'message' => $status === 'published'
                ? '投稿を公開しました。'
                : '下書きを保存しました。',
            'post' => $post,
        ], 201);
    }

    public function update(Request $request, Post $post)
    {
        if ((int) $post->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'exists:categories,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

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

        return response()->json([
            'message' => $status === 'published'
                ? '投稿を公開しました。'
                : '下書きを保存しました。',
            'post' => $post->fresh(),
        ]);
    }
}
