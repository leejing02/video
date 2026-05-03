<?php

namespace App\Filament\Resources\LongVideoCategoryResource\Pages;

use App\Filament\Resources\LongVideoCategoryResource;
use App\Models\Category;
use Filament\Resources\Pages\CreateRecord;

class CreateLongVideoCategory extends CreateRecord
{
    protected static string $resource = LongVideoCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 默认创建在长视频分类范围
        if (empty($data['kind'])) {
            $data['kind'] = Category::KIND_LONG;
        }
        return $data;
    }
}
