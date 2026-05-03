<?php

namespace App\Policies;

use App\Models\ChatRoom;
use App\Models\User;

class ChatRoomPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool { return $user->can('chat_room.view'); }
    public function view(User $user, ChatRoom $r): bool   { return $user->can('chat_room.view'); }
    public function create(User $user): bool  { return $user->can('chat_room.create'); }
    public function update(User $user, ChatRoom $r): bool { return $user->can('chat_room.update'); }
    public function delete(User $user, ChatRoom $r): bool
    {
        // 全局群聊禁止删除
        if ($r->kind === ChatRoom::KIND_GLOBAL) {
            return false;
        }
        return $user->can('chat_room.delete');
    }
}
