<?php

namespace App\Filament\Resources\ShortVideoResource\Pages;

use App\Filament\Resources\ShortVideoResource;
use App\Models\Video;
use Filament\Resources\Pages\CreateRecord;

class CreateShortVideo extends CreateRecord
{
    protected static string $resource = ShortVideoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = Video::TYPE_SHORT;
        return $data;
    }
}
