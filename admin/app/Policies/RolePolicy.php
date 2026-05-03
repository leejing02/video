<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool { return $user->can('role.view'); }
    public function view(User $user, Role $r): bool   { return $user->can('role.view'); }
    public function create(User $user): bool  { return $user->can('role.create'); }
    public function update(User $user, Role $r): bool { return $user->can('role.update'); }
    public function delete(User $user, Role $r): bool
    {
        if ($r->name === 'super-admin') {
            return false; // 永远不允许删 super-admin 角色
        }
        return $user->can('role.delete');
    }
}
