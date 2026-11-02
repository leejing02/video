<?php

namespace App\Filament\Resources\LiveCategoryResource\Pages;

use App\Filament\Resources\LiveCategoryResource;
use App\Models\Category;
use Filament\Resources\Pages\CreateRecord;

class CreateLiveCategory extends CreateRecord
{
    protected static string $resource = LiveCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['type'])) {
            $data['type'] = Category::TYPE_LIVE;
        }
        return $data;
    }
}
