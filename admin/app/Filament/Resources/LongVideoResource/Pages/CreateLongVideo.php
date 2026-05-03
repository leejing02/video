<?php

namespace App\Filament\Resources\LongVideoResource\Pages;

use App\Filament\Resources\LongVideoResource;
use App\Models\Video;
use Filament\Resources\Pages\CreateRecord;

class CreateLongVideo extends CreateRecord
{
    protected static string $resource = LongVideoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = Video::TYPE_LONG;
        return $data;
    }
}
