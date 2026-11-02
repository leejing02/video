<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LiveCategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class LiveCategoryResource extends CategoryResource
{
    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationIcon  = 'heroicon-o-signal';
    protected static ?string $navigationGroup = '内容管理';
    protected static ?string $navigationLabel = '直播分类';
    protected static ?string $modelLabel      = '直播分类';
    protected static ?int $navigationSort     = 23;
    protected static ?string $slug            = 'live-categories';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', Category::TYPE_LIVE);
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
                Forms\Components\Hidden::make('type')->default(Category::TYPE_LIVE),
                Forms\Components\TextInput::make('sort')->label('排序')->numeric()->default(0),
                Forms\Components\TextInput::make('icon')
                    ->label('图标（CDN url 或 SF Symbol 名）')
                    ->placeholder('antenna.radiowaves.left.and.right'),
                Forms\Components\Toggle::make('is_active')->label('启用')->default(true),
            ]),
            Forms\Components\FileUpload::make('cover')
                ->label('封面图')
                ->image()
                ->directory('categories/live'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover')->label('封面')->square(),
                Tables\Columns\TextColumn::make('name')->label('名称')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->label('Slug')->copyable(),
                Tables\Columns\TextColumn::make('sort')->label('排序')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('启用'),
                Tables\Columns\TextColumn::make('created_at')->label('创建时间')->dateTime('m-d H:i')->sortable()->toggleable(),
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
            'index'  => Pages\ListLiveCategories::route('/'),
            'create' => Pages\CreateLiveCategory::route('/create'),
            'edit'   => Pages\EditLiveCategory::route('/{record}/edit'),
        ];
    }
}
