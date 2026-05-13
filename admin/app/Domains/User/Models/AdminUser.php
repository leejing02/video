<?php

namespace App\Domains\User\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * 后台运营账号。与 C 端 App\Domains\User\Models\User 完全分离：
 *  - 物理隔离：不同表、不同 model、不同 guard
 *  - Spatie 权限通过 $guard_name='admin' 与前端 web guard 区分
 *  - 仅 is_active=true 才允许进 /admin
 *
 * 业务上的"是否能看某个 Resource"由 Policy + Permission 决定，
 * super-admin 角色由 AppServiceProvider 的 Gate::before 一刀放行。
 */
class AdminUser extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /** Spatie Permission：限定本模型的角色/权限只在 admin guard 下查找 */
    protected string $guard_name = 'admin';

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'is_active',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password'      => 'hashed',
            'is_active'     => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /* ================ Filament ================ */

    public function canAccessPanel(Panel $panel): bool
    {
        // 进面板的硬条件 = 启用。具体能看哪个 Resource 由 Policy 控制。
        return (bool) $this->is_active;
    }

    public function getFilamentName(): string
    {
        return $this->name ?: $this->username;
    }

    /* ================ Helpers ================ */

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }
}
