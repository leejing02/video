<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER  = 'user';

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'avatar',
        'phone',
        'bio',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    public static function adminUploadOwner(): self
    {
        $owner = self::firstOrCreate(
            ['username' => 'efv-system-admin'],
            [
                'name' => '管理员',
                'email' => 'efv-system-admin@system.invalid',
                'password' => \Illuminate\Support\Str::random(64),
                'role' => self::ROLE_USER,
                'is_active' => false,
            ],
        );

        abort_unless(!$owner->is_active && $owner->email === 'efv-system-admin@system.invalid',
            409, '管理员上传归属账号冲突，请检查 efv-system-admin');

        return $owner;
    }

    /* -------------------- Filament -------------------- */

    /**
     * 进 /admin 后台的硬条件：账号启用 + 拥有任意一个角色（哪怕只有"viewer"）
     * 角色里再细分能看哪些 Resource，由 Policy + 权限 name 控制。
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }
        // role 字段是历史遗留：admin 直接放行；其他用户只要有角色就能进
        return $this->role === self::ROLE_ADMIN
            || $this->roles()->exists();
    }

    /** 是否有"超级管理员"角色 — 拥有所有权限 */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    /* -------------------- Relations -------------------- */

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function chatRooms(): BelongsToMany
    {
        return $this->belongsToMany(ChatRoom::class)
            ->withPivot(['role', 'muted', 'joined_at', 'last_read_at'])
            ->withTimestamps();
    }

    public function likedVideos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class, 'video_likes')->withTimestamps();
    }

    public function likedComments(): BelongsToMany
    {
        return $this->belongsToMany(Comment::class, 'comment_likes')->withTimestamps();
    }

    /* -------------------- Helpers -------------------- */

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN || $this->isSuperAdmin();
    }
}
