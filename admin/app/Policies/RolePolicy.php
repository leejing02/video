<?php

namespace App\Policies;

use App\Domains\User\Models\AdminUser;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function before(AdminUser $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(AdminUser $user): bool { return $user->can('role.view'); }
    public function view(AdminUser $user, Role $r): bool   { return $user->can('role.view'); }
    public function create(AdminUser $user): bool  { return $user->can('role.create'); }
    public function update(AdminUser $user, Role $r): bool { return $user->can('role.update'); }
    public function delete(AdminUser $user, Role $r): bool
    {
        if ($r->name === 'super-admin') {
            return false; // 永远不允许删 super-admin 角色
        }
        return $user->can('role.delete');
    }
}
