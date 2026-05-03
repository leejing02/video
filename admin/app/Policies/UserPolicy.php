<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /** super-admin 一律放行 */
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool   { return $user->can('user.view'); }
    public function view(User $user, User $r): bool   { return $user->can('user.view'); }
    public function create(User $user): bool    { return $user->can('user.create'); }
    public function update(User $user, User $r): bool { return $user->can('user.update'); }
    public function delete(User $user, User $r): bool
    {
        // 不允许删除自己 + 不允许删除 super-admin
        if ($user->id === $r->id) {
            return false;
        }
        if ($r->hasRole('super-admin')) {
            return false;
        }
        return $user->can('user.delete');
    }
}
