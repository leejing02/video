<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    // 基类：菜单走 LongVideoCategoryResource / ShortVideoCategoryResource
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon  = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = '内容管理';
    protected static ?string $navigationLabel = '分类（全部）';
    protected static ?string $modelLabel      = '分类';
    protected static ?int $navigationSort     = 99;

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
                    ->label('类型')
                    ->options([
                        Category::KIND_LONG  => '长视频',
                        Category::KIND_SHORT => '短视频',
                        Category::KIND_BOTH  => '通用',
                    ])
                    ->default(Category::KIND_BOTH)
                    ->required(),
                Forms\Components\TextInput::make('sort')
                    ->label('排序')
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('icon')
                    ->label('图标（CDN url 或 SF Symbol 名）')
                    ->placeholder('https://... 或 film.fill'),
                Forms\Components\Toggle::make('is_active')
                    ->label('启用')
                    ->default(true),
            ]),
            Forms\Components\FileUpload::make('cover')
                ->label('封面图')
                ->image()
                ->directory('categories'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover')
                    ->label('封面')
                    ->square(),
                Tables\Columns\TextColumn::make('name')
                    ->label('名称')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')->label('Slug')->copyable(),
                Tables\Columns\BadgeColumn::make('kind')
                    ->label('类型')
                    ->colors([
                        'primary' => Category::KIND_LONG,
                        'success' => Category::KIND_SHORT,
                        'gray'    => Category::KIND_BOTH,
                    ])
                    ->formatStateUsing(fn ($s) => match ($s) {
                        Category::KIND_LONG  => '长视频',
                        Category::KIND_SHORT => '短视频',
                        default              => '通用',
                    }),
                Tables\Columns\TextColumn::make('videos_count')
                    ->counts('videos')
                    ->label('视频数')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort')->label('排序')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('启用'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->label('类型')
                    ->options([
                        Category::KIND_LONG  => '长视频',
                        Category::KIND_SHORT => '短视频',
                        Category::KIND_BOTH  => '通用',
                    ]),
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
            'index'  => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit'   => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
