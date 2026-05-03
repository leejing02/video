<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChatMessageResource\Pages;
use App\Models\ChatMessage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ChatMessageResource extends Resource
{
    protected static ?string $model = ChatMessage::class;

    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-bottom-center-text';
    protected static ?string $navigationGroup = '聊天';
    protected static ?string $navigationLabel = '聊天消息';
    protected static ?string $modelLabel      = '聊天消息';
    protected static ?int $navigationSort     = 51;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Select::make('chat_room_id')
                    ->label('聊天室')
                    ->relationship('chatRoom', 'name')
                    ->searchable()
                    ->required(),
                Forms\Components\Select::make('user_id')
                    ->label('发送人')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->required(),
                Forms\Components\Select::make('type')
                    ->label('消息类型')
                    ->options([
                        ChatMessage::TYPE_TEXT   => '文本',
                        ChatMessage::TYPE_IMAGE  => '图片',
                        ChatMessage::TYPE_VIDEO  => '视频',
                        ChatMessage::TYPE_SYSTEM => '系统',
                    ])
                    ->default(ChatMessage::TYPE_TEXT)
                    ->required(),
                Forms\Components\Select::make('reply_to_id')
                    ->label('回复消息')
                    ->relationship('replyTo', 'content')
                    ->searchable(),
            ]),
            Forms\Components\Textarea::make('content')->label('内容')->rows(3)->required(),
            Forms\Components\TextInput::make('attachment_url')->label('附件 URL')->url(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('chatRoom.name')->label('聊天室')->searchable(),
                Tables\Columns\TextColumn::make('user.name')->label('发送人')->searchable(),
                Tables\Columns\BadgeColumn::make('type')
                    ->label('类型')
                    ->colors([
                        'gray'    => ChatMessage::TYPE_TEXT,
                        'primary' => ChatMessage::TYPE_IMAGE,
                        'success' => ChatMessage::TYPE_VIDEO,
                        'danger'  => ChatMessage::TYPE_SYSTEM,
                    ]),
                Tables\Columns\TextColumn::make('content')
                    ->label('内容')
                    ->limit(60)
                    ->wrap(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('时间')
                    ->dateTime('Y-m-d H:i:s')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('chat_room_id')
                    ->label('聊天室')
                    ->relationship('chatRoom', 'name'),
                Tables\Filters\SelectFilter::make('type')
                    ->label('类型')
                    ->options([
                        ChatMessage::TYPE_TEXT   => '文本',
                        ChatMessage::TYPE_IMAGE  => '图片',
                        ChatMessage::TYPE_VIDEO  => '视频',
                        ChatMessage::TYPE_SYSTEM => '系统',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            'index'  => Pages\ListChatMessages::route('/'),
            'create' => Pages\CreateChatMessage::route('/create'),
            'edit'   => Pages\EditChatMessage::route('/{record}/edit'),
        ];
    }
}
