<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CommentResource\Pages;
use App\Models\Comment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = '用户与互动';
    protected static ?string $navigationLabel = '评论';
    protected static ?string $modelLabel      = '评论';
    protected static ?int $navigationSort     = 40;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Select::make('user_id')
                    ->label('用户')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->required(),
                Forms\Components\Select::make('video_id')
                    ->label('视频')
                    ->relationship('video', 'title')
                    ->searchable()
                    ->required(),
                Forms\Components\Select::make('parent_id')
                    ->label('回复')
                    ->relationship('parent', 'content')
                    ->searchable()
                    ->placeholder('— 顶级评论 —'),
                Forms\Components\Toggle::make('is_pinned')->label('置顶'),
            ]),
            Forms\Components\Textarea::make('content')
                ->label('内容')
                ->rows(4)
                ->required()
                ->maxLength(2000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('用户')
                    ->searchable(),
                Tables\Columns\TextColumn::make('video.title')
                    ->label('视频')
                    ->limit(30)
                    ->searchable(),
                Tables\Columns\TextColumn::make('content')
                    ->label('内容')
                    ->limit(60)
                    ->wrap(),
                Tables\Columns\TextColumn::make('parent_id')
                    ->label('层级')
                    ->formatStateUsing(fn ($s) => $s ? '回复' : '顶级')
                    ->badge(),
                Tables\Columns\IconColumn::make('is_pinned')->boolean()->label('置顶'),
                Tables\Columns\TextColumn::make('likes')->sortable()->label('点赞'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('时间')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_pinned')->label('置顶'),
                Tables\Filters\Filter::make('roots_only')
                    ->label('只看顶级评论')
                    ->query(fn ($query) => $query->whereNull('parent_id')),
            ])
            ->actions([
                Tables\Actions\Action::make('pin')
                    ->label('置顶/取消')
                    ->icon('heroicon-o-bookmark')
                    ->action(fn (Comment $r) => $r->update(['is_pinned' => ! $r->is_pinned])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListComments::route('/'),
            'create' => Pages\CreateComment::route('/create'),
            'edit'   => Pages\EditComment::route('/{record}/edit'),
        ];
    }
}
