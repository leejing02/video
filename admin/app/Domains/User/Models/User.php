<?php

namespace App\Domains\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Domains\Chat\Models\ChatMessage;
use App\Domains\Chat\Models\ChatRoom;
use App\Domains\Video\Models\Comment;
use App\Domains\Video\Models\Video;


/**
 * C 端用户。仅用于：
 *  - Go API / Sanctum 鉴权（HasApiTokens）
 *  - 视频/评论/聊天等业务关联
 *
 * ❌ 不再实现 FilamentUser，不再有后台 role 概念。
 *    后台账号请使用 App\Domains\User\Models\AdminUser（admin_users 表）。
 *
 * NOTE: users.role 列暂时保留为历史遗留字段（未来 migration 删除），
 *      代码层不再读它，新增逻辑禁止依赖它。
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'avatar',
        'phone',
        'bio',
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
}
