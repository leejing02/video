<?php

namespace App\Filament\Resources\ShortVideoResource\Pages;

use App\Filament\Resources\ShortVideoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShortVideos extends ListRecords
{
    protected static string $resource = ShortVideoResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('上传短视频')];
    }
}
