<?php

namespace App\Filament\Resources\ShortVideoCategoryResource\Pages;

use App\Filament\Resources\ShortVideoCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShortVideoCategory extends EditRecord
{
    protected static string $resource = ShortVideoCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
