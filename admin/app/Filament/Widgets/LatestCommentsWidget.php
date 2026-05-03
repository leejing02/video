<?php

namespace App\Filament\Widgets;

use App\Models\Comment;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestCommentsWidget extends BaseWidget
{
    protected static ?int $sort = 4;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = '最新评论（10 条）';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Comment::query()
                    ->with(['user:id,name,username,avatar', 'video:id,title'])
                    ->latest()
                    ->limit(10)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\ImageColumn::make('user.avatar')
                    ->label('')
                    ->circular()
                    ->size(36),
                Tables\Columns\TextColumn::make('user.name')->label('用户')->searchable(),
                Tables\Columns\TextColumn::make('content')
                    ->label('内容')
                    ->limit(80)
                    ->wrap(),
                Tables\Columns\TextColumn::make('video.title')
                    ->label('视频')
                    ->limit(30),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('时间')
                    ->since(),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()->label('删除'),
            ]);
    }
}
