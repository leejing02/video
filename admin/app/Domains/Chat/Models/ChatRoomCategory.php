<?php

namespace App\Domains\Chat\Models;

use App\Domains\Video\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 聊天室分类。底层是 categories 表（type='chat'）。
 * 提供独立的 model class 让"聊天室分类"在 Filament 菜单单独成项。
 */
class ChatRoomCategory extends Category
{
    protected $table = 'categories';

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('chat', function (Builder $builder) {
            $builder->where('type', self::TYPE_CHAT);
        });

        static::saving(function (Category $category) {
            if (empty($category->type)) {
                $category->type = self::TYPE_CHAT;
            }
        });
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'category_id');
    }
}
