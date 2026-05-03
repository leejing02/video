<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function index(Video $video): JsonResponse
    {
        $roots = $video->comments()
            ->with([
                'user:id,name,username,avatar',
                'replies.user:id,name,username,avatar',
            ])
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($roots);
    }

    public function store(Request $request, Video $video): JsonResponse
    {
        $data = $request->validate([
            'content'   => ['required', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer', 'exists:comments,id'],
        ]);

        $comment = $video->allComments()->create([
            'user_id'   => $request->user()->id,
            'parent_id' => $data['parent_id'] ?? null,
            'content'   => $data['content'],
        ]);

        $comment->load('user:id,name,username,avatar');
        return response()->json($comment, 201);
    }

    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        if ($comment->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            return response()->json(['message' => 'forbidden'], 403);
        }
        $comment->delete();
        return response()->json(['ok' => true]);
    }

    public function like(Request $request, Comment $comment): JsonResponse
    {
        $user = $request->user();
        $existed = $comment->likers()->where('user_id', $user->id)->exists();

        if ($existed) {
            $comment->likers()->detach($user->id);
            $comment->decrement('likes');
            return response()->json(['liked' => false, 'likes' => $comment->fresh()->likes]);
        }

        $comment->likers()->attach($user->id);
        $comment->increment('likes');
        return response()->json(['liked' => true, 'likes' => $comment->fresh()->likes]);
    }
}
