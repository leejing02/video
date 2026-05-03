<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon  = 'heroicon-o-users';
    protected static ?string $navigationGroup = '用户与互动';
    protected static ?string $navigationLabel = '用户';
    protected static ?string $modelLabel      = '用户';
    protected static ?int $navigationSort     = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('基础信息')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('昵称')
                        ->required()
                        ->maxLength(60),
                    Forms\Components\TextInput::make('username')
                        ->label('用户名')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(40)
                        ->alphaDash(),
                    Forms\Components\TextInput::make('email')
                        ->label('邮箱')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('phone')
                        ->label('手机号')
                        ->tel()
                        ->unique(ignoreRecord: true),
                    Forms\Components\Select::make('role')
                        ->label('用户类型（前台用）')
                        ->options([
                            User::ROLE_ADMIN => '管理员',
                            User::ROLE_USER  => '普通用户',
                        ])
                        ->default(User::ROLE_USER)
                        ->required()
                        ->helperText('这个字段只控制 iOS App 内显示；后台权限走"角色"字段'),
                    Forms\Components\Toggle::make('is_active')
                        ->label('启用')
                        ->default(true)
                        ->helperText('禁用后无法登录、无法访问后台'),
                ]),

            Forms\Components\Section::make('后台角色（决定能进哪些菜单）')
                ->schema([
                    Forms\Components\Select::make('roles')
                        ->label('角色')
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->helperText('留空表示无后台权限；通常给"super-admin"或"editor"等'),
                ]),

            Forms\Components\Section::make('扩展资料')
                ->columns(2)
                ->collapsed()
                ->schema([
                    Forms\Components\FileUpload::make('avatar')
                        ->label('头像')
                        ->image()
                        ->avatar()
                        ->directory('avatars'),
                    Forms\Components\Textarea::make('bio')
                        ->label('简介')
                        ->rows(3)
                        ->maxLength(500),
                ]),

            Forms\Components\Section::make('密码')
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->label('密码')
                        ->password()
                        ->revealable()
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $operation) => $operation === 'create')
                        ->minLength(6)
                        ->helperText('编辑时留空 = 不改密码'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar')
                    ->label('头像')
                    ->circular()
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?name=U'),
                Tables\Columns\TextColumn::make('name')
                    ->label('昵称')
                    ->searchable(),
                Tables\Columns\TextColumn::make('username')
                    ->label('用户名')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('邮箱')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('后台角色')
                    ->badge()
                    ->separator(',')
                    ->placeholder('无'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('启用')
                    ->boolean(),
                Tables\Columns\TextColumn::make('videos_count')
                    ->label('视频数')
                    ->counts('videos')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('注册时间')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label('后台角色')
                    ->relationship('roles', 'name')
                    ->multiple(),
                Tables\Filters\TernaryFilter::make('is_active')->label('启用'),
            ])
            ->actions([
                Tables\Actions\Action::make('toggle_active')
                    ->label(fn (User $r) => $r->is_active ? '禁用' : '启用')
                    ->icon(fn (User $r) => $r->is_active ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (User $r) => $r->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->action(function (User $r) {
                        $r->update(['is_active' => ! $r->is_active]);
                        // 禁用时撤销该用户所有 API token
                        if (! $r->is_active) {
                            $r->tokens()->delete();
                        }
                        Notification::make()
                            ->title($r->is_active ? '已启用' : '已禁用并下线')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reset_password')
                    ->label('重置密码')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('会生成一个临时密码，本次只显示一次，记得立刻给用户。')
                    ->action(function (User $r) {
                        $newPwd = Str::random(10);
                        $r->update(['password' => Hash::make($newPwd)]);
                        $r->tokens()->delete();
                        Notification::make()
                            ->title("临时密码：{$newPwd}")
                            ->body('已通知所有 token 失效，请用户立即重新登录并改密')
                            ->warning()
                            ->persistent()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $r) => $r->id !== auth()->id()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('disable')
                        ->label('批量禁用')
                        ->icon('heroicon-o-lock-closed')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each(function ($u) {
                            $u->update(['is_active' => false]);
                            $u->tokens()->delete();
                        })),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
