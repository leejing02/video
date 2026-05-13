<?php

namespace App\Domains\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Domains\User\Models\User;


class ChatRoom extends Model
{
    use HasFactory;

    public const TYPE_PUBLIC = 'public';
    public const TYPE_GROUP  = 'group';

    public const TYPES = [
        self::TYPE_PUBLIC => '公开聊天室',
        self::TYPE_GROUP  => '普通群',
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'cover',
        'type',
        'category_id',
        'is_active',
        'all_muted',
        'member_limit',
        'owner_id',
        'last_message_at',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'all_muted'       => 'boolean',
        'member_limit'    => 'integer',
        'last_message_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ChatRoom $room) {
            if (empty($room->slug)) {
                $room->slug = Str::slug($room->name) . '-' . Str::random(6);
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function category(): BelongsTo
    {
        // 统一 categories 表，type='chat'
        return $this->belongsTo(ChatRoomCategory::class, 'category_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'muted', 'muted_until', 'mute_reason', 'joined_at', 'last_read_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        // 列名是 room_id，显式声明免得 Eloquent 默认猜测 chat_room_id
        return $this->hasMany(ChatMessage::class, 'room_id');
    }

    public function latestMessages(int $limit = 50): HasMany
    {
        return $this->messages()
            ->with(['user:id,name,username,avatar', 'replyTo'])
            ->latest()
            ->limit($limit);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PUBLIC);
    }

    /** 兼容旧 API：返回任意一间公开聊天室（首页"广场"） */
    public static function publicRoom(): ?self
    {
        return static::query()->where('type', self::TYPE_PUBLIC)->first();
    }
}
