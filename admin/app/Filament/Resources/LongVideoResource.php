<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LongVideoResource\Pages;
use App\Models\Category;
use App\Models\Video;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LongVideoResource extends VideoResource
{
    // 让导航重新可见
    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationIcon  = 'heroicon-o-film';
    protected static ?string $navigationGroup = '内容管理';
    protected static ?string $navigationLabel = '长视频';
    protected static ?string $modelLabel      = '长视频';
    protected static ?int $navigationSort     = 31;
    protected static ?string $slug            = 'long-videos';

    /** 列表只看长视频 */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', Video::TYPE_LONG);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('基础信息')->columns(2)->schema([
                Forms\Components\TextInput::make('title')
                    ->label('标题')
                    ->required()
                    ->maxLength(150)
                    ->columnSpanFull(),
                Forms\Components\Hidden::make('type')->default(Video::TYPE_LONG),
                Forms\Components\Select::make('category_id')
                    ->label('分类')
                    ->relationship(
                        name: 'category',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $q) => $q
                            ->where('is_active', true)
                            ->whereIn('kind', [Category::KIND_LONG, Category::KIND_BOTH])
                    )
                    ->searchable()->preload()->required(),
                Forms\Components\Select::make('user_id')
                    ->label('上传者')
                    ->relationship('user', 'name')
                    ->searchable()->preload()->required(),
                Forms\Components\Select::make('status')
                    ->label('状态')
                    ->options([
                        Video::STATUS_DRAFT     => '草稿',
                        Video::STATUS_PUBLISHED => '已发布',
                        Video::STATUS_ARCHIVED  => '已下架',
                    ])
                    ->default(Video::STATUS_PUBLISHED)
                    ->required(),
                Forms\Components\TextInput::make('duration')
                    ->label('时长（秒）')
                    ->numeric()
                    ->default(0),
            ]),

            Forms\Components\Section::make('内容')->schema([
                Forms\Components\Textarea::make('description')->label('描述')->rows(4)->maxLength(2000),
                Forms\Components\TextInput::make('url')
                    ->label('视频 URL')
                    ->url()->required()
                    ->placeholder('https://cdn.example.com/video.mp4'),
                Forms\Components\FileUpload::make('cover')->label('封面')->image()->directory('videos/covers'),
                Forms\Components\DateTimePicker::make('published_at')->label('发布时间')->default(now()),
            ]),

            Forms\Components\Section::make('统计（只读）')->columns(3)->collapsed()->schema([
                Forms\Components\TextInput::make('views')->label('播放量')->numeric()->disabled()->default(0),
                Forms\Components\TextInput::make('likes')->label('点赞')->numeric()->disabled()->default(0),
                Forms\Components\TextInput::make('comments_count')->label('评论数')->numeric()->disabled()->default(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover')->label('封面')->square()->size(60),
                Tables\Columns\TextColumn::make('title')->label('标题')->searchable()->limit(40)->wrap(),
                Tables\Columns\TextColumn::make('category.name')->label('分类')->badge(),
                Tables\Columns\TextColumn::make('user.name')->label('上传者')->searchable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('状态')
                    ->colors([
                        'gray'    => Video::STATUS_DRAFT,
                        'success' => Video::STATUS_PUBLISHED,
                        'danger'  => Video::STATUS_ARCHIVED,
                    ])
                    ->formatStateUsing(fn ($s) => match ($s) {
                        Video::STATUS_DRAFT     => '草稿',
                        Video::STATUS_PUBLISHED => '已发布',
                        Video::STATUS_ARCHIVED  => '已下架',
                    }),
                Tables\Columns\TextColumn::make('duration')
                    ->label('时长')
                    ->formatStateUsing(fn ($s) => sprintf('%02d:%02d', intdiv((int)$s, 60), (int)$s % 60)),
                Tables\Columns\TextColumn::make('views')->label('播放')->sortable(),
                Tables\Columns\TextColumn::make('likes')->label('赞')->sortable(),
                Tables\Columns\TextColumn::make('comments_count')->label('评论')->sortable(),
                Tables\Columns\TextColumn::make('published_at')->label('发布')->dateTime('m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('状态')
                    ->options([
                        Video::STATUS_DRAFT     => '草稿',
                        Video::STATUS_PUBLISHED => '已发布',
                        Video::STATUS_ARCHIVED  => '已下架',
                    ]),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('分类')
                    ->relationship('category', 'name', fn (Builder $q) => $q->whereIn('kind', [Category::KIND_LONG, Category::KIND_BOTH])),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label('预览')
                    ->icon('heroicon-o-play')
                    ->url(fn (Video $r) => $r->url, shouldOpenInNewTab: true),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('publish')
                        ->label('批量发布')->icon('heroicon-o-check')
                        ->action(fn ($records) => $records->each->update(['status' => Video::STATUS_PUBLISHED])),
                    Tables\Actions\BulkAction::make('archive')
                        ->label('批量下架')->icon('heroicon-o-archive-box')
                        ->action(fn ($records) => $records->each->update(['status' => Video::STATUS_ARCHIVED])),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListLongVideos::route('/'),
            'create' => Pages\CreateLongVideo::route('/create'),
            'edit'   => Pages\EditLongVideo::route('/{record}/edit'),
        ];
    }
}
