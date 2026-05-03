<?php

namespace App\Policies;

use App\Models\ChatMessage;
use App\Models\User;

class ChatMessagePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool { return $user->can('chat_message.view'); }
    public function view(User $user, ChatMessage $r): bool   { return $user->can('chat_message.view'); }
    public function create(User $user): bool  { return false; }
    public function update(User $user, ChatMessage $r): bool { return false; }
    public function delete(User $user, ChatMessage $r): bool { return $user->can('chat_message.delete'); }
}
