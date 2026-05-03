<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShortVideoCategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ShortVideoCategoryResource extends CategoryResource
{
    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationIcon  = 'heroicon-o-folder-open';
    protected static ?string $navigationGroup = '内容管理';
    protected static ?string $navigationLabel = '短视频分类';
    protected static ?string $modelLabel      = '短视频分类';
    protected static ?int $navigationSort     = 22;
    protected static ?string $slug            = 'short-categories';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('kind', [Category::KIND_SHORT, Category::KIND_BOTH]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('name')
                    ->label('名称')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('slug', Str::slug($state))),
                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
                    ->unique(ignoreRecord: true)
                    ->required(),
                Forms\Components\Select::make('kind')
                    ->label('使用范围')
                    ->options([
                        Category::KIND_SHORT => '仅短视频',
                        Category::KIND_BOTH  => '通用（长 + 短）',
                    ])
                    ->default(Category::KIND_SHORT)
                    ->required(),
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
                Tables\Columns\TextColumn::make('slug')->label('Slug')->copyable(),
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
