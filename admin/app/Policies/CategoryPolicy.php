<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    private function permFor(Category $r, string $action): string
    {
        // long / both → long_category；short → short_category；
        // both 优先匹配 long_category，权限不够 fall back
        $resource = match ($r->kind) {
            Category::KIND_SHORT => 'short_category',
            default              => 'long_category',
        };
        return "{$resource}.{$action}";
    }

    public function viewAny(User $user): bool
    {
        return $user->canAny(['long_category.view', 'short_category.view']);
    }

    public function view(User $user, Category $r): bool
    {
        return $user->can($this->permFor($r, 'view'));
    }

    public function create(User $user): bool
    {
        return $user->canAny(['long_category.create', 'short_category.create']);
    }

    public function update(User $user, Category $r): bool
    {
        return $user->can($this->permFor($r, 'update'));
    }

    public function delete(User $user, Category $r): bool
    {
        return $user->can($this->permFor($r, 'delete'));
    }
}
