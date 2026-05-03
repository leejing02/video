<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::active()->orderBy('sort');

        if ($kind = $request->query('kind')) {
            $query->forKind($kind);
        }

        return response()->json($query->get());
    }
}
