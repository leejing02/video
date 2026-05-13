<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShortVideoCategoryResource\Pages;
use App\Domains\Video\Models\Category;
use App\Domains\Video\Models\ShortVideoCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 短视频分类（categories.type='short' 的子集）。
 * Model 上有 global scope，查询自动加 type='short'，无需重复 where。
 */
class ShortVideoCategoryResource extends CategoryResource
{
    protected static ?string $model = ShortVideoCategory::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationIcon  = 'heroicon-o-folder-open';
    protected static ?string $navigationGroup = '内容管理';
    protected static ?string $navigationLabel = '短视频分类';
    protected static ?string $modelLabel      = '短视频分类';
    protected static ?int $navigationSort     = 22;
    protected static ?string $slug            = 'short-categories';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('name')->label('名称')->required(),
                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder('保存后自动生成'),
                Forms\Components\Select::make('parent_id')
                    ->label('父分类')
                    ->options(fn () => Category::query()
                        ->where('type', Category::TYPE_SHORT)
                        ->where('is_active', true)
                        ->orderBy('sort')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->placeholder('— 顶级 —'),
                Forms\Components\TextInput::make('sort')->label('排序')->numeric()->default(0),
                Forms\Components\TextInput::make('icon')
                    ->label('图标（CDN url 或 SF Symbol 名）')
                    ->placeholder('face.smiling'),
                Forms\Components\Toggle::make('is_active')->label('启用')->default(true),
            ]),
            Forms\Components\FileUpload::make('cover')
                ->label('封面图')
                ->image()
                ->directory('categories/short'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover')->label('封面')->square(),
                Tables\Columns\TextColumn::make('name')->label('名称')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->label('Slug')->copyable()->toggleable(),
                Tables\Columns\TextColumn::make('parent.name')->label('父分类')->placeholder('—'),
                Tables\Columns\TextColumn::make('short_videos_count')
                    ->counts('shortVideos')
                    ->label('短视频数')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort')->label('排序')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('启用'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('启用'),
            ])
            ->reorderable('sort')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListShortVideoCategories::route('/'),
            'create' => Pages\CreateShortVideoCategory::route('/create'),
            'edit'   => Pages\EditShortVideoCategory::route('/{record}/edit'),
        ];
    }
}
