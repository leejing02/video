<?php

namespace App\Filament\Resources\ShortVideoCategoryResource\Pages;

use App\Filament\Resources\ShortVideoCategoryResource;
use App\Models\Category;
use Filament\Resources\Pages\CreateRecord;

class CreateShortVideoCategory extends CreateRecord
{
    protected static string $resource = ShortVideoCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['kind'])) {
            $data['kind'] = Category::KIND_SHORT;
        }
        return $data;
    }
}
