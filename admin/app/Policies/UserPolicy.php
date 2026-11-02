<?php

namespace App\Policies;

use App\Domains\User\Models\AdminUser;
use App\Models\User;

class UserPolicy
{
    /** super-admin 一律放行 */
    public function before(AdminUser $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(AdminUser $user): bool   { return $user->can('user.view'); }
    public function view(AdminUser $user, User $r): bool   { return $user->can('user.view'); }
    public function create(AdminUser $user): bool    { return $user->can('user.create'); }
    public function update(AdminUser $user, User $r): bool { return $user->can('user.update'); }
    public function delete(AdminUser $user, User $r): bool
    {
        // 不允许删除自己 + 不允许删除 super-admin
        if ($r->hasRole('super-admin')) {
            return false;
        }
        return $user->can('user.delete');
    }
}
