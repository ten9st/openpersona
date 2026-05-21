<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $perPage = $validated['per_page'] ?? 20;

        $query = Post::with([
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
            'message' => '投稿を作成しました。',
            'post' => $post,
        ], 201);
    }
}
