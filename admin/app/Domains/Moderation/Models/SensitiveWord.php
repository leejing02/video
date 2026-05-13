<?php

namespace App\Domains\Moderation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SensitiveWord extends Model
{
    use HasFactory;

    public const SEVERITY_WARN  = 'warn';   // 命中后标记待审，但允许提交
    public const SEVERITY_BLOCK = 'block';  // 命中直接拒绝

    public const CATEGORIES = [
        'politics'   => '政治',
        'porn'       => '色情',
        'violence'   => '暴力',
        'spam'       => '广告/营销',
        'other'      => '其他',
    ];

    protected $fillable = [
        'word', 'category', 'severity', 'is_active', 'hits',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'hits'      => 'integer',
    ];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    protected static function booted(): void
    {
        $flush = function () {
            try { app(\App\Services\ContentModerator::class)->flushCache(); }
            catch (\Throwable) { /* ignore */ }
        };
        static::saved($flush);
        static::deleted($flush);
    }
}
