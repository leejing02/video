<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatMessage $message)
    {
        $this->message->loadMissing(['user:id,name,username,avatar', 'replyTo']);
    }

    /**
     * 同时广播到普通频道（兜底）和 presence 频道（在线列表）
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('chat.room.' . $this->message->chat_room_id),
            new PresenceChannel('chat.presence.' . $this->message->chat_room_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        $m = $this->message;

        return [
            'id'             => $m->id,
            'chat_room_id'   => $m->chat_room_id,
            'type'           => $m->type,
            'content'        => $m->content,
            'attachment_url' => $m->attachment_url,
            'reply_to_id'    => $m->reply_to_id,
            'created_at'     => $m->created_at?->toIso8601String(),
            'user'           => [
                'id'       => $m->user?->id,
                'name'     => $m->user?->name,
                'username' => $m->user?->username,
                'avatar'   => $m->user?->avatar,
            ],
        ];
    }
}
