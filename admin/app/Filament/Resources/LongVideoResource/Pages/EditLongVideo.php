<?php

namespace App\Filament\Resources\LongVideoResource\Pages;

use App\Filament\Resources\LongVideoResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLongVideo extends EditRecord
{
    protected static string $resource = LongVideoResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
