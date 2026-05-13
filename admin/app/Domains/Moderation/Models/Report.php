<?php

namespace App\Domains\Moderation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Domains\User\Models\User;


class Report extends Model
{
    use HasFactory;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_HANDLED   = 'handled';
    public const STATUS_DISMISSED = 'dismissed';

    public const REASONS = [
        'porn'        => '色情',
        'violence'    => '暴力',
        'spam'        => '广告',
        'fraud'       => '诈骗',
        'politics'    => '违规言论',
        'harassment'  => '骚扰',
        'other'       => '其他',
    ];

    protected $fillable = [
        'reporter_id', 'reportable_type', 'reportable_id',
        'reason', 'description', 'status',
        'handled_by', 'handled_at', 'handler_note',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePending($q)   { return $q->where('status', self::STATUS_PENDING); }
    public function scopeHandled($q)   { return $q->where('status', self::STATUS_HANDLED); }
}
