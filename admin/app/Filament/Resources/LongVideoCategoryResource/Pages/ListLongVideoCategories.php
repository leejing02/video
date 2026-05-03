<?php

namespace App\Filament\Resources\LongVideoCategoryResource\Pages;

use App\Filament\Resources\LongVideoCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLongVideoCategories extends ListRecords
{
    protected static string $resource = LongVideoCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('新建分类')];
    }
}
