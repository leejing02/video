<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Video extends Model
{
    use HasFactory;

    public const TYPE_LONG  = 'long';
    public const TYPE_SHORT = 'short';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED  = 'archived';

    protected $fillable = [
        'user_id',
        'category_id',
        'type',
        'title',
        'description',
        'cover',
        'url',
        'duration',
        'views',
        'likes',
        'comments_count',
        'status',
        'published_at',
    ];

    protected $casts = [
        'duration'       => 'integer',
        'views'          => 'integer',
        'likes'          => 'integer',
        'comments_count' => 'integer',
        'published_at'   => 'datetime',
    ];

    /* -------------------- Relations -------------------- */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->whereNull('parent_id');
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function likers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'video_likes')->withTimestamps();
    }

    /* -------------------- Scopes -------------------- */

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeLong($query)
    {
        return $query->where('type', self::TYPE_LONG);
    }

    public function scopeShort($query)
    {
        return $query->where('type', self::TYPE_SHORT);
    }

    public function scopeOfCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /* -------------------- Helpers -------------------- */

    public function incrementViews(): void
    {
        $this->increment('views');
    }
}
