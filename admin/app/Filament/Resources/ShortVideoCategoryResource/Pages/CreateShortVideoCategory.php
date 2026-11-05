<?php

namespace App\Filament\Resources\ShortVideoCategoryResource\Pages;

use App\Filament\Resources\ShortVideoCategoryResource;
use App\Models\Category;
use App\Services\EfvCategorySync;
use DomainException;
use Illuminate\Validation\ValidationException;
use Filament\Resources\Pages\CreateRecord;

class CreateShortVideoCategory extends CreateRecord
{
    protected static string $resource = ShortVideoCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['type'])) {
            $data['type'] = Category::TYPE_SHORT;
        }
        try {
            app(EfvCategorySync::class)->ensure((string) $data['name']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['data.name' => $exception->getMessage()]);
        }

        return $data;
    }
}
