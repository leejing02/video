<?php

namespace App\Policies;

use App\Domains\User\Models\AdminUser;
use App\Models\Video;

class VideoPolicy
{
    public function before(AdminUser $user): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    /** 长 / 短分别走不同权限名 */
    private function permFor(Video $r, string $action): string
    {
        $resource = $r->type === Video::TYPE_LONG ? 'long_video' : 'short_video';
        return "{$resource}.{$action}";
    }

    public function viewAny(AdminUser $user): bool
    {
        // 列表页能进只要有 long_video.view 或 short_video.view 之一
        return $user->canAny(['long_video.view', 'short_video.view']);
    }

    public function view(AdminUser $user, Video $r): bool
    {
        return $user->can($this->permFor($r, 'view'));
    }

    public function create(AdminUser $user): bool
    {
        return $user->canAny(['long_video.create', 'short_video.create']);
    }

    public function update(AdminUser $user, Video $r): bool
    {
        return $user->can($this->permFor($r, 'update'));
    }

    public function delete(AdminUser $user, Video $r): bool
    {
        return $user->can($this->permFor($r, 'delete'));
    }
}
