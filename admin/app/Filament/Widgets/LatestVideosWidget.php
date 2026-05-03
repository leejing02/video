<?php

namespace App\Filament\Widgets;

use App\Models\Video;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestVideosWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = '最新视频（10 条）';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Video::query()
                    ->with(['user:id,name', 'category:id,name'])
                    ->latest()
                    ->limit(10)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\ImageColumn::make('cover')
                    ->label('封面')
                    ->square()
                    ->size(48)
                    ->defaultImageUrl(fn () => 'https://placehold.co/96x54/eee/333?text=video'),
                Tables\Columns\TextColumn::make('title')
                    ->label('标题')
                    ->limit(40)
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('type')
                    ->label('类型')
                    ->colors([
                        'primary' => Video::TYPE_LONG,
                        'success' => Video::TYPE_SHORT,
                    ])
                    ->formatStateUsing(fn ($s) => $s === Video::TYPE_LONG ? '长视频' : '短视频'),
                Tables\Columns\TextColumn::make('category.name')->label('分类')->badge(),
                Tables\Columns\TextColumn::make('user.name')->label('上传者'),
                Tables\Columns\TextColumn::make('views')->label('播放')->sortable(),
                Tables\Columns\TextColumn::make('likes')->label('赞')->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('上传时间')
                    ->dateTime('m-d H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('查看')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Video $r) => route('filament.admin.resources.videos.edit', $r)),
            ]);
    }
}
