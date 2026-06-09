<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = Tag::query()->orderBy('name');

        if (! empty($validated['search'])) {
            $keyword = $validated['search'];
            $query->where('name', 'like', '%'.$keyword.'%');
        }

        $tags = $query
            ->limit(20)
            ->get(['id', 'name', 'slug']);

        return response()->json([
            'tags' => $tags,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = trim($validated['name']);

        if ($name === '') {
            return response()->json([
                'message' => 'タグ名を入力してください。',
            ], 422);
        }

        $existing = Tag::findByName($name);

        if ($existing !== null) {
            return response()->json([
                'tag' => $existing->only(['id', 'name', 'slug']),
                'created' => false,
            ]);
        }

        $tag = Tag::createFromName($name);

        return response()->json([
            'tag' => $tag->only(['id', 'name', 'slug']),
            'created' => true,
        ], 201);
    }
}
