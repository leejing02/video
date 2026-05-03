<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ChatRoom extends Model
{
    use HasFactory;

    public const KIND_GLOBAL = 'global';
    public const KIND_GROUP  = 'group';
    public const KIND_DIRECT = 'direct';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'cover',
        'kind',
        'is_active',
        'owner_id',
        'last_message_at',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
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

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'muted', 'joined_at', 'last_read_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function latestMessages(int $limit = 50): HasMany
    {
        return $this->messages()
            ->with(['user:id,name,username,avatar', 'replyTo'])
            ->latest()
            ->limit($limit);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeGlobal($query)
    {
        return $query->where('kind', self::KIND_GLOBAL);
    }

    public static function globalRoom(): ?self
    {
        return static::query()->where('kind', self::KIND_GLOBAL)->first();
    }
}
