<?php

namespace App\Domains\Video\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Domains\Chat\Models\ChatRoom;


/**
 * 统一分类表。靠 type 列区分语义：
 *   - TYPE_VIDEO : 长视频分类（Video 模型）
 *   - TYPE_SHORT : 短视频分类（ShortVideo 模型）
 *   - TYPE_LIVE  : 直播源分类（LiveSource 模型）
 *   - TYPE_CHAT  : 聊天室分类（ChatRoom 模型）
 *
 * 同一张表，避免 N 套结构重复 schema/Resource/Policy。
 * 支持 parent_id 父子层级。
 */
class Category extends Model
{
    use HasFactory;

    public const TYPE_VIDEO = 'video';
    public const TYPE_SHORT = 'short';
    public const TYPE_LIVE  = 'live';
    public const TYPE_CHAT  = 'chat';

    public const TYPES = [
        self::TYPE_VIDEO => '长视频',
        self::TYPE_SHORT => '短视频',
        self::TYPE_LIVE  => '直播',
        self::TYPE_CHAT  => '聊天',
    ];

    protected $fillable = [
        'name',
        'slug',
        'type',
        'parent_id',
        'icon',
        'cover',
        'description',
        'sort',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort'      => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Category $category) {
            if (! empty($category->slug)) {
                return;
            }

            $base = Str::slug((string) $category->name);
            if ($base === '') {
                $base = 'cat';
            }

            $slug = $base;
            $i    = 1;
            while (
                static::query()
                    ->where('slug', $slug)
                    ->when($category->exists, fn ($q) => $q->whereKeyNot($category->getKey()))
                    ->exists()
            ) {
                $slug = $base . '-' . $i++;
            }

            $category->slug = $slug;
        });
    }

    /* -------------------- Hierarchy -------------------- */

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /* -------------------- Domain relations -------------------- */

    /** 该 type='video' 分类下的长视频 */
    // public function videos(): HasMany
    // {
    //     return $this->hasMany(Video::class)
    //         ->where('type', Video::TYPE_LONG);
    // }

    // /** 该 type='short' 分类下的短视频（同样在 videos 表，靠 type 区分） */
    // public function shortVideos(): HasMany
    // {
    //     return $this->hasMany(Video::class)
    //         ->where('type', Video::TYPE_SHORT);
    // }
    public function videos(): HasMany
    {
        return $this->hasMany(Video::class, 'category_id')
            ->where('type', Video::TYPE_LONG);
    }

    /** 该 type='short' 分类下的短视频（同样在 videos 表，靠 type 区分） */
    public function shortVideos(): HasMany
    {
        return $this->hasMany(Video::class, 'category_id')
            ->where('type', Video::TYPE_SHORT);
    }


    public function chatRooms(): HasMany
    {
        return $this->hasMany(ChatRoom::class);
    }

    /* -------------------- Scopes -------------------- */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
