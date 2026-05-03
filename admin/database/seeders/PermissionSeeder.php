<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * 权限命名规范：<resource>.<action>
     * action：view / create / update / delete
     */
    private const PERMISSIONS = [
        // 用户与角色
        'user.view', 'user.create', 'user.update', 'user.delete',
        'role.view', 'role.create', 'role.update', 'role.delete',
        // 视频
        'long_video.view',  'long_video.create',  'long_video.update',  'long_video.delete',
        'short_video.view', 'short_video.create', 'short_video.update', 'short_video.delete',
        // 分类
        'long_category.view',  'long_category.create',  'long_category.update',  'long_category.delete',
        'short_category.view', 'short_category.create', 'short_category.update', 'short_category.delete',
        // 评论
        'comment.view', 'comment.delete',
        // 聊天
        'chat_room.view',    'chat_room.create',    'chat_room.update',    'chat_room.delete',
        'chat_message.view', 'chat_message.delete',
    ];

    private const ROLES = [
        'super-admin' => '*', // 全部
        'editor'      => [
            'long_video.*', 'short_video.*',
            'long_category.*', 'short_category.*',
            'comment.view', 'comment.delete',
        ],
        'moderator'   => [
            'comment.view', 'comment.delete',
            'chat_message.view', 'chat_message.delete',
            'chat_room.view',
            'long_video.view', 'short_video.view',
        ],
        'viewer'      => ['*.view'], // 只读所有
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. 写权限
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // 2. 写角色 + 关联权限
        foreach (self::ROLES as $roleName => $patterns) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            if ($patterns === '*') {
                $role->syncPermissions(Permission::all());
                continue;
            }

            $perms = collect();
            foreach ($patterns as $p) {
                $perms = $perms->merge($this->matchPermissions($p));
            }
            $role->syncPermissions($perms->unique()->values());
        }

        // 3. 把现有 admin 用户提升为 super-admin
        if ($admin = User::where('email', 'admin@example.com')->first()) {
            $admin->syncRoles(['super-admin']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** 把 long_video.* 这种通配符展开成实际的权限集合 */
    private function matchPermissions(string $pattern): \Illuminate\Support\Collection
    {
        $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';
        return Permission::where('guard_name', 'web')
            ->get()
            ->filter(fn ($p) => preg_match($regex, $p->name));
    }
}
