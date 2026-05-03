<?php

namespace App\Filament\Resources\LongVideoResource\Pages;

use App\Filament\Resources\LongVideoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLongVideos extends ListRecords
{
    protected static string $resource = LongVideoResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('上传长视频')];
    }
}
