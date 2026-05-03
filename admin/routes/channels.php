<?php

use App\Models\ChatRoom;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| 私有 / Presence 频道授权。客户端连 ws 后会先打 /broadcasting/auth
| 我们在这里判断用户能不能进这个房间。
*/

Broadcast::channel('chat.room.{roomId}', function ($user, int $roomId) {
    $room = ChatRoom::find($roomId);
    if (! $room || ! $room->is_active) {
        return false;
    }
    // 全局群所有人都能听
    if ($room->kind === ChatRoom::KIND_GLOBAL) {
        return true;
    }
    return $room->users()->where('users.id', $user->id)->exists();
});

Broadcast::channel('chat.presence.{roomId}', function ($user, int $roomId) {
    $room = ChatRoom::find($roomId);
    if (! $room || ! $room->is_active) {
        return false;
    }
    if ($room->kind !== ChatRoom::KIND_GLOBAL
        && ! $room->users()->where('users.id', $user->id)->exists()) {
        return false;
    }
    return [
        'id'       => $user->id,
        'name'     => $user->name,
        'username' => $user->username,
        'avatar'   => $user->avatar,
    ];
});
