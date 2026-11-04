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
        return [
            Actions\Action::make('uploadGallery')->label('批量上传')
                ->url(ShortVideoResource::getUrl('upload-gallery'))
                ->visible(fn () => UploadGallery::canAccess()),
            Actions\CreateAction::make()->label('上传短视频'),
        ];
    }
}
