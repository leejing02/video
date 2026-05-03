<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChatRoomResource\Pages;
use App\Models\ChatRoom;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ChatRoomResource extends Resource
{
    protected static ?string $model = ChatRoom::class;

    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-ellipsis';
    protected static ?string $navigationGroup = '聊天';
    protected static ?string $navigationLabel = '聊天室';
    protected static ?string $modelLabel      = '聊天室';
    protected static ?int $navigationSort     = 50;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('基础信息')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label('名称')->required(),
                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
                    ->unique(ignoreRecord: true)
                    ->helperText('留空自动生成'),
                Forms\Components\Select::make('kind')
                    ->label('类型')
                    ->options([
                        ChatRoom::KIND_GLOBAL => '全局群聊（首页）',
                        ChatRoom::KIND_GROUP  => '普通群',
                        ChatRoom::KIND_DIRECT => '私聊',
                    ])
                    ->default(ChatRoom::KIND_GROUP)
                    ->required(),
                Forms\Components\Select::make('owner_id')
                    ->label('群主')
                    ->relationship('owner', 'name')
                    ->searchable(),
                Forms\Components\Toggle::make('is_active')->label('启用')->default(true),
            ]),

            Forms\Components\Textarea::make('description')->label('简介')->rows(3),

            Forms\Components\FileUpload::make('cover')
                ->label('封面')
                ->image()
                ->directory('chat-rooms'),

            Forms\Components\Section::make('成员')->schema([
                Forms\Components\Select::make('users')
                    ->label('成员')
                    ->relationship('users', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('全局群聊可不指定成员（所有人都能进）'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover')->label('封面')->square(),
                Tables\Columns\TextColumn::make('name')->label('名称')->searchable(),
                Tables\Columns\BadgeColumn::make('kind')
                    ->label('类型')
                    ->colors([
                        'danger'  => ChatRoom::KIND_GLOBAL,
                        'primary' => ChatRoom::KIND_GROUP,
                        'gray'    => ChatRoom::KIND_DIRECT,
                    ])
                    ->formatStateUsing(fn ($s) => match ($s) {
                        ChatRoom::KIND_GLOBAL => '全局',
                        ChatRoom::KIND_GROUP  => '普通群',
                        ChatRoom::KIND_DIRECT => '私聊',
                    }),
                Tables\Columns\TextColumn::make('owner.name')->label('群主'),
                Tables\Columns\TextColumn::make('users_count')
                    ->counts('users')
                    ->label('成员数'),
                Tables\Columns\TextColumn::make('messages_count')
                    ->counts('messages')
                    ->label('消息数'),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('启用'),
                Tables\Columns\TextColumn::make('last_message_at')
                    ->label('最后消息')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->label('类型')
                    ->options([
                        ChatRoom::KIND_GLOBAL => '全局',
                        ChatRoom::KIND_GROUP  => '普通群',
                        ChatRoom::KIND_DIRECT => '私聊',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('last_message_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListChatRooms::route('/'),
            'create' => Pages\CreateChatRoom::route('/create'),
            'edit'   => Pages\EditChatRoom::route('/{record}/edit'),
        ];
    }
}
