<?php

namespace App\Filament\Resources\ShortVideoCategoryResource\Pages;

use App\Filament\Resources\ShortVideoCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShortVideoCategories extends ListRecords
{
    protected static string $resource = ShortVideoCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('新建分类')];
    }
}
