<?php

namespace App\Domains\Video\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * DEPRECATED：保留类符号纯粹是为了向后兼容历史导入引用。
 *
 * 数据上：不再有 short_videos 表，短视频 = videos 表中 type='short' 的行。
 * 这个类继承 Video（同表 `videos`）+ 全局 scope，让历史代码继续可用：
 *   ShortVideo::query()->get()        // 自动过滤 type='short'
 *   ShortVideo::find(...)
 *
 * 新代码请直接用 Video 模型并显式 where('type', 'short')。
 */
class ShortVideo extends Video
{
    protected $table = 'videos';

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('short', function (Builder $builder) {
            $builder->where('type', Video::TYPE_SHORT);
        });

        static::saving(function (Video $video) {
            if (empty($video->type)) {
                $video->type = Video::TYPE_SHORT;
            }
        });
    }
}
