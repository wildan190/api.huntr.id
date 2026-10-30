<?php

namespace App\Domain\News\Http\Controllers;

use App\Domain\News\Services\NewsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsController
{
    public function index(Request $request, NewsService $news): JsonResponse
    {
        $category = $request->validate([
            'category' => ['nullable', 'in:all,economy,politics'],
        ])['category'] ?? 'all';

        return response()->json($news->headlines($category));
    }
}
