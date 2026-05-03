<?php

namespace App\Filament\Widgets;

use App\Models\ChatRoom;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ActiveChatRoomsWidget extends BaseWidget
{
    protected static ?int $sort = 8;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = '活跃聊天室（按最后消息）';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ChatRoom::query()
                    ->withCount(['users', 'messages'])
                    ->orderByDesc('last_message_at')
                    ->limit(8)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('名称'),
                Tables\Columns\BadgeColumn::make('kind')
                    ->label('类型')
                    ->colors([
                        'danger'  => ChatRoom::KIND_GLOBAL,
                        'primary' => ChatRoom::KIND_GROUP,
                        'gray'    => ChatRoom::KIND_DIRECT,
                    ]),
                Tables\Columns\TextColumn::make('users_count')->label('成员'),
                Tables\Columns\TextColumn::make('messages_count')->label('消息'),
                Tables\Columns\TextColumn::make('last_message_at')
                    ->label('最后活跃')
                    ->since(),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('查看')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ChatRoom $r) => route('filament.admin.resources.chat-rooms.edit', $r)),
            ]);
    }
}
