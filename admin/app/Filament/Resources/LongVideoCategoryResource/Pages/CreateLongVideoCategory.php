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
        if (empty($data['type'])) {
            $data['type'] = Category::TYPE_VIDEO;
        }
        return $data;
    }
}
