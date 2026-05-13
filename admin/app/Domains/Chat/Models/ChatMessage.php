<?php

namespace App\Domains\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Domains\User\Models\User;


class ChatMessage extends Model
{
    use HasFactory;

    public const TYPE_TEXT   = 'text';
    public const TYPE_IMAGE  = 'image';
    public const TYPE_VIDEO  = 'video';
    public const TYPE_SYSTEM = 'system';

    protected $fillable = [
        'room_id',
        'user_id',
        'type',
        'content',
        'reply_to_id',
        'attachment_url',
        'meta',
        'is_blocked',
        'blocked_reason',
    ];

    protected $casts = [
        'meta'       => 'array',
        'is_blocked' => 'boolean',
    ];

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_blocked', false);
    }

    protected static function booted(): void
    {
        static::created(function (ChatMessage $message) {
            $message->room?->update(['last_message_at' => $message->created_at]);
        });
    }

    /** 与 chat_rooms 的关联。列名 room_id，关系方法也用 room() 统一命名 */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'room_id');
    }

    /**
     * 兼容旧调用点（如 Filament Resource 写过 chat_room.name）。
     * 建议新代码统一用 room()。
     */
    public function chatRoom(): BelongsTo
    {
        return $this->room();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_id');
    }
}
