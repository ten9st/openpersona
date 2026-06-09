<?php

namespace App\Http\Controllers;

use App\Models\Category;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::query()
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'posting_age_limit', 'sort_order']);

        return response()->json([
            'categories' => $categories,
        ]);
    }
}
