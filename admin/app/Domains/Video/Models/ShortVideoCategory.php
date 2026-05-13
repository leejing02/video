<?php

namespace App\Domains\Video\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * 短视频分类。底层就是 categories 表（type='short'），
 * 用全局 scope + saving 钩子保证查询和新建都自动归到 short 类型。
 *
 * 这个子类只是给 Filament Resource 提供一个独立的 model class，
 * 让"短视频分类"和"长视频分类"在后台菜单上各自一张列表。
 */
class ShortVideoCategory extends Category
{
    protected $table = 'categories';

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('short', function (Builder $builder) {
            $builder->where('type', self::TYPE_SHORT);
        });

        static::saving(function (Category $category) {
            if (empty($category->type)) {
                $category->type = self::TYPE_SHORT;
            }
        });
    }
}
