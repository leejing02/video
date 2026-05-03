<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool { return $user->can('comment.view'); }
    public function view(User $user, Comment $r): bool   { return $user->can('comment.view'); }
    public function create(User $user): bool  { return false; } // 后台不让人替用户写评论
    public function update(User $user, Comment $r): bool { return false; }
    public function delete(User $user, Comment $r): bool { return $user->can('comment.delete'); }
}
