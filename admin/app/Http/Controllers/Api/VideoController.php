<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type'); // long | short
        $categoryId = $request->query('category_id');

        $query = Video::published()
            ->with(['user:id,name,username,avatar', 'category:id,name,slug,kind'])
            ->orderByDesc('published_at');

        if (in_array($type, [Video::TYPE_LONG, Video::TYPE_SHORT], true)) {
            $query->where('type', $type);
        }
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
        if ($keyword = $request->query('q')) {
            $query->where('title', 'like', "%{$keyword}%");
        }

        return response()->json($query->paginate((int) $request->query('per_page', 20)));
    }

    public function show(Video $video): JsonResponse
    {
        $video->incrementViews();
        $video->load(['user:id,name,username,avatar', 'category:id,name,slug']);
        return response()->json($video);
    }

    public function like(Request $request, Video $video): JsonResponse
    {
        $user = $request->user();
        $existed = $video->likers()->where('user_id', $user->id)->exists();

        if ($existed) {
            $video->likers()->detach($user->id);
            $video->decrement('likes');
            return response()->json(['liked' => false, 'likes' => $video->fresh()->likes]);
        }

        $video->likers()->attach($user->id);
        $video->increment('likes');
        return response()->json(['liked' => true, 'likes' => $video->fresh()->likes]);
    }

    public function mine(Request $request): JsonResponse
    {
        $videos = $request->user()
            ->videos()
            ->with('category:id,name')
            ->latest()
            ->paginate(20);

        return response()->json($videos);
    }
}
