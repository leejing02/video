<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShortVideoResource\Pages;
use App\Models\Category;
use App\Models\Video;
use App\Services\EfvShortVideoSync;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ShortVideoResource extends VideoResource
{
    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationIcon  = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = '内容管理';
    protected static ?string $navigationLabel = '短视频';
    protected static ?string $modelLabel      = '短视频';
    protected static ?int $navigationSort     = 32;
    protected static ?string $slug            = 'short-videos';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', Video::TYPE_SHORT);
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
                Forms\Components\Hidden::make('type')->default(Video::TYPE_SHORT),
                Forms\Components\Select::make('category_id')
                    ->label('分类')
                    ->relationship(
                        name: 'category',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query
                            ->where('is_active', true)
                            ->where('type', Category::TYPE_SHORT)
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
                    ->default(15)
                    ->helperText('短视频建议 ≤ 60 秒'),
            ]),

            Forms\Components\Section::make('内容')->schema([
                Forms\Components\Textarea::make('description')->label('描述')->rows(3)->maxLength(500),
                Forms\Components\TextInput::make('url')
                    ->label('视频 URL')->url()->required()
                    ->placeholder('https://cdn.example.com/short.mp4'),
                Forms\Components\Placeholder::make('remote_cover_preview')
                    ->label('当前远程封面')
                    ->visible(fn (?Video $record) => str_starts_with((string) $record?->cover, 'https://'))
                    ->content(fn (?Video $record) => new \Illuminate\Support\HtmlString(
                        '<img width="120" alt="当前封面" src="'.e($record?->cover).'">'
                    )),
                Forms\Components\FileUpload::make('cover')
                    ->label('封面（竖图）')
                    ->image()
                    ->imageResizeMode('cover')
                    ->imageCropAspectRatio('9:16')
                    ->directory('shorts/covers'),
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
                Tables\Columns\ImageColumn::make('cover')->label('封面')->square()->size(48),
                Tables\Columns\TextColumn::make('title')->label('标题')->searchable()->limit(30)->wrap(),
                Tables\Columns\TextColumn::make('category.name')->label('分类')->badge()->color('success'),
                Tables\Columns\TextColumn::make('user.name')->label('上传者')->searchable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('状态')
                    ->colors([
                        'gray'    => Video::STATUS_DRAFT,
                        'success' => Video::STATUS_PUBLISHED,
                        'danger'  => Video::STATUS_ARCHIVED,
                    ])
                    ->formatStateUsing(fn ($state, Video $record) => match ($state) {
                        Video::STATUS_DRAFT     => blank($record->url) ? '待补播放地址' : '草稿',
                        Video::STATUS_PUBLISHED => '已发布',
                        Video::STATUS_ARCHIVED  => '已下架',
                    }),
                Tables\Columns\TextColumn::make('duration')
                    ->label('时长')
                    ->formatStateUsing(fn ($state) => $state . 's'),
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
                Tables\Filters\SelectFilter::make('category')
                    ->label('分类')
                    ->relationship('category', 'name', fn (Builder $query) => $query->where('type', Category::TYPE_SHORT))
                    ->preload()
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label('预览')
                    ->icon('heroicon-o-play')
                    ->visible(fn (Video $record) => filled($record->url))
                    ->url(fn (Video $r) => $r->url, shouldOpenInNewTab: true),
                Tables\Actions\Action::make('syncEfv')
                    ->label('同步 EFV')
                    ->visible(fn (Video $record) => static::canEdit($record)
                        && (!filled($record->url) || !filled($record->cover) || str_starts_with((string) $record->cover, 'https://')
                            || str_starts_with((string) $record->cover, 'shorts/covers/efv-'))
                        && app(EfvShortVideoSync::class)->movieId($record) !== null)
                    ->requiresConfirmation()
                    ->modalDescription('获取这条视频的 EFV 转码结果并发布到前台。')
                    ->action(function (Video $record) {
                        abort_unless(static::canEdit($record), 403);

                        try {
                            $warning = app(EfvShortVideoSync::class)->publish($record);
                        } catch (DomainException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        if ($warning) {
                            Notification::make()->warning()->title($warning)->send();
                        } else {
                            Notification::make()->success()->title('已同步并发布到前台')->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('publish')
                        ->label('批量发布')->icon('heroicon-o-check')
                        ->action(function ($records) {
                            $ready = $records->filter(fn (Video $record) => filled($record->url));
                            $ready->each->update(['status' => Video::STATUS_PUBLISHED]);
                            if ($ready->count() < $records->count()) {
                                \Filament\Notifications\Notification::make()
                                    ->warning()->title('缺少播放地址的视频仍保留为草稿')->send();
                            }
                        }),
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
            'index'  => Pages\ListShortVideos::route('/'),
            'create' => Pages\CreateShortVideo::route('/create'),
            'upload-history' => Pages\GalleryUploadHistory::route('/upload-history'),
            'upload-gallery' => Pages\UploadGallery::route('/upload-gallery'),
            'edit'   => Pages\EditShortVideo::route('/{record}/edit'),
        ];
    }
}
