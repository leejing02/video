<?php

namespace App\Filament\Resources\VideoResource\Pages;

use App\Filament\Resources\VideoResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListVideos extends ListRecords
{
    protected static string $resource = VideoResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    public function getTabs(): array
    {
        return [
            'all'   => Tab::make('全部'),
            'long'  => Tab::make('长视频')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('type', 'long')),
            'short' => Tab::make('短视频')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('type', 'short')),
            'draft' => Tab::make('草稿')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('status', 'draft')),
        ];
    }
}
