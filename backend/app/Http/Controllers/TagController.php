<?php

namespace App\Http\Controllers;

use App\Domain\Post\QueryServices\TagQueryService;
use App\Domain\Post\Services\TagService;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request, TagQueryService $tagQueryService)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'tags' => $tagQueryService->search($validated['search'] ?? null),
        ]);
    }

    public function store(Request $request, TagService $tagService)
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

        $result = $tagService->findOrCreateByName($name);

        return response()->json([
            'tag' => $result['tag']->only(['id', 'name', 'slug']),
            'created' => $result['created'],
        ], $result['created'] ? 201 : 200);
    }
}
