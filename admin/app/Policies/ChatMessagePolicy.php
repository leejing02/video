<?php

namespace App\Policies;

use App\Domains\User\Models\AdminUser;
use App\Models\ChatMessage;

class ChatMessagePolicy
{
    public function before(AdminUser $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(AdminUser $user): bool { return $user->can('chat_message.view'); }
    public function view(AdminUser $user, ChatMessage $r): bool   { return $user->can('chat_message.view'); }
    public function create(AdminUser $user): bool  { return false; }
    public function update(AdminUser $user, ChatMessage $r): bool { return false; }
    public function delete(AdminUser $user, ChatMessage $r): bool { return $user->can('chat_message.delete'); }
}
