<?php

namespace App\Policies;

use App\Domains\User\Models\AdminUser;
use App\Models\ChatRoom;

class ChatRoomPolicy
{
    public function before(AdminUser $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(AdminUser $user): bool { return $user->can('chat_room.view'); }
    public function view(AdminUser $user, ChatRoom $r): bool   { return $user->can('chat_room.view'); }
    public function create(AdminUser $user): bool  { return $user->can('chat_room.create'); }
    public function update(AdminUser $user, ChatRoom $r): bool { return $user->can('chat_room.update'); }
    public function delete(AdminUser $user, ChatRoom $r): bool
    {
        // 全局群聊禁止删除
        if ($r->kind === ChatRoom::KIND_GLOBAL) {
            return false;
        }
        return $user->can('chat_room.delete');
    }
}
