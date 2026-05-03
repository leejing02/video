<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    public const KIND_LONG  = 'long';
    public const KIND_SHORT = 'short';
    public const KIND_BOTH  = 'both';

    protected $fillable = [
        'name',
        'slug',
        'kind',
        'icon',
        'cover',
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
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function longVideos(): HasMany
    {
        return $this->hasMany(Video::class)->where('type', Video::TYPE_LONG);
    }

    public function shortVideos(): HasMany
    {
        return $this->hasMany(Video::class)->where('type', Video::TYPE_SHORT);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForKind($query, string $kind)
    {
        return $query->whereIn('kind', [$kind, self::KIND_BOTH]);
    }
}
