<?php

namespace App\Policies;

use App\Domains\User\Models\AdminUser;
use App\Models\Comment;

class CommentPolicy
{
    public function before(AdminUser $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(AdminUser $user): bool { return $user->can('comment.view'); }
    public function view(AdminUser $user, Comment $r): bool   { return $user->can('comment.view'); }
    public function create(AdminUser $user): bool  { return false; } // 后台不让人替用户写评论
    public function update(AdminUser $user, Comment $r): bool { return false; }
    public function delete(AdminUser $user, Comment $r): bool { return $user->can('comment.delete'); }
}
