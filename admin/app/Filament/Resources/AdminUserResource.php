<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdminUserResource\Pages;
use App\Domains\User\Models\AdminUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * 后台账号管理。仅有 admin_user.* 权限的账号可访问。
 * 创建/编辑时同步 spatie 角色（admin guard 下的）。
 */
class AdminUserResource extends Resource
{
    protected static ?string $model = AdminUser::class;

    protected static ?string $navigationIcon  = 'heroicon-o-shield-exclamation';
    protected static ?string $navigationGroup = '后台账号';
    protected static ?string $navigationLabel = '后台账号';
    protected static ?string $modelLabel      = '后台账号';
    protected static ?int $navigationSort     = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('基本信息')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('姓名')
                        ->required()
                        ->maxLength(60),
                    Forms\Components\TextInput::make('username')
                        ->label('登录账号')
                        ->required()
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->maxLength(60),
                    Forms\Components\TextInput::make('email')
                        ->label('邮箱')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true),
                    Forms\Components\Toggle::make('is_active')
                        ->label('启用')
                        ->default(true)
                        ->helperText('禁用后无法登录后台'),
                ]),

            Forms\Components\Section::make('密码')
                ->description('留空表示不修改')
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->label('密码')
                        ->password()
                        ->revealable()
                        ->rule(\Illuminate\Validation\Rules\Password::min(8))
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : null)
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $operation) => $operation === 'create'),
                ]),

            Forms\Components\Section::make('角色')
                ->description('super-admin 拥有所有权限，谨慎赋予')
                ->schema([
                    Forms\Components\CheckboxList::make('roles')
                        ->label('')
                        ->relationship(
                            name: 'roles',
                            titleAttribute: 'name',
                            modifyQueryUsing: fn ($query) => $query->where('guard_name', 'admin'),
                        )
                        ->columns(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('name')->label('姓名')->searchable(),
                Tables\Columns\TextColumn::make('username')->label('账号')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('email')->label('邮箱')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('角色')
                    ->badge()
                    ->separator(',')
                    ->placeholder('无'),
                Tables\Columns\IconColumn::make('is_active')->label('启用')->boolean(),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('最近登录')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('创建时间')
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('启用'),
                Tables\Filters\SelectFilter::make('roles')
                    ->label('角色')
                    ->relationship(
                        name: 'roles',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query->where('guard_name', 'admin'),
                    )
                    ->multiple(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    // super-admin 不允许在列表里直接删（防止把全部 super-admin 删光后无法登录）
                    ->visible(fn (AdminUser $r) => ! $r->hasRole('super-admin')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAdminUsers::route('/'),
            'create' => Pages\CreateAdminUser::route('/create'),
            'edit'   => Pages\EditAdminUser::route('/{record}/edit'),
        ];
    }
}
