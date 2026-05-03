<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    /** 列出当前用户能进的所有聊天室（含全局） */
    public function rooms(Request $request): JsonResponse
    {
        $user = $request->user();

        $rooms = ChatRoom::active()
            ->where(function ($q) use ($user) {
                $q->where('kind', ChatRoom::KIND_GLOBAL)
                  ->orWhereHas('users', fn ($q2) => $q2->where('users.id', $user->id));
            })
            ->withCount('users')
            ->orderByDesc('last_message_at')
            ->get();

        return response()->json($rooms);
    }

    /** 全局首页群聊（首页展示用） */
    public function globalRoom(): JsonResponse
    {
        $room = ChatRoom::globalRoom();
        abort_unless($room, 404, 'global room not configured');
        return response()->json($room);
    }

    /** 历史消息（分页，反向滚动） */
    public function messages(Request $request, ChatRoom $room): JsonResponse
    {
        $this->ensureCanRead($request, $room);

        $beforeId = $request->query('before_id'); // 游标
        $query = $room->messages()
            ->with(['user:id,name,username,avatar', 'replyTo'])
            ->orderByDesc('id')
            ->limit((int) $request->query('limit', 30));

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        return response()->json($query->get()->reverse()->values());
    }

    /** 发消息（落库 + 广播） */
    public function send(Request $request, ChatRoom $room): JsonResponse
    {
        $this->ensureCanRead($request, $room);

        $data = $request->validate([
            'type'           => ['nullable', 'in:text,image,video'],
            'content'        => ['required', 'string', 'max:2000'],
            'reply_to_id'    => ['nullable', 'integer', 'exists:chat_messages,id'],
            'attachment_url' => ['nullable', 'url'],
        ]);

        $message = $room->messages()->create([
            'user_id'        => $request->user()->id,
            'type'           => $data['type'] ?? ChatMessage::TYPE_TEXT,
            'content'        => $data['content'],
            'reply_to_id'    => $data['reply_to_id'] ?? null,
            'attachment_url' => $data['attachment_url'] ?? null,
        ]);

        broadcast(new MessageSent($message))->toOthers();

        $message->load('user:id,name,username,avatar');
        return response()->json($message, 201);
    }

    /** 加入聊天室 */
    public function join(Request $request, ChatRoom $room): JsonResponse
    {
        if ($room->kind === ChatRoom::KIND_GLOBAL) {
            // 全局群无需加入
            return response()->json(['ok' => true, 'global' => true]);
        }
        $request->user()->chatRooms()->syncWithoutDetaching([
            $room->id => ['joined_at' => now(), 'role' => 'member'],
        ]);
        return response()->json(['ok' => true]);
    }

    /** 标记已读 */
    public function markRead(Request $request, ChatRoom $room): JsonResponse
    {
        $request->user()->chatRooms()->syncWithoutDetaching([
            $room->id => ['last_read_at' => now()],
        ]);
        return response()->json(['ok' => true]);
    }

    private function ensureCanRead(Request $request, ChatRoom $room): void
    {
        if ($room->kind === ChatRoom::KIND_GLOBAL) {
            return;
        }
        $isMember = $room->users()->where('users.id', $request->user()->id)->exists();
        abort_unless($isMember, 403, 'not a member of this room');
    }
}
