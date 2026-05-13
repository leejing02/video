<?php

namespace App\Domains\Video\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Domains\User\Models\User;


class Comment extends Model
{
    use HasFactory;

    public const AUDIT_PENDING  = 'pending';
    public const AUDIT_APPROVED = 'approved';
    public const AUDIT_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'video_id',
        'parent_id',
        'content',
        'likes',
        'is_pinned',
        'audit_status',
        'audit_note',
        'audited_by',
        'audited_at',
    ];

    protected $casts = [
        'likes'      => 'integer',
        'is_pinned'  => 'boolean',
        'audited_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (Comment $comment) {
            $comment->video?->increment('comments_count');
        });

        static::deleted(function (Comment $comment) {
            $comment->video?->decrement('comments_count');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }

    public function likers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'comment_likes')->withTimestamps();
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopePendingAudit($query)
    {
        return $query->where('audit_status', self::AUDIT_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('audit_status', self::AUDIT_APPROVED);
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audited_by');
    }
}
