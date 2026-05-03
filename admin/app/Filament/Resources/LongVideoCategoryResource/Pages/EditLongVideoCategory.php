<?php

namespace App\Filament\Resources\LongVideoCategoryResource\Pages;

use App\Filament\Resources\LongVideoCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLongVideoCategory extends EditRecord
{
    protected static string $resource = LongVideoCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
