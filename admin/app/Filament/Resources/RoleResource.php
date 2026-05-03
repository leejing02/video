<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon  = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = '用户与互动';
    protected static ?string $navigationLabel = '角色';
    protected static ?string $modelLabel      = '角色';
    protected static ?int $navigationSort     = 11;

    public static function form(Form $form): Form
    {
        // 把所有权限按 resource 前缀分组（user.* / long_video.* ...）
        $allPermissions = Permission::where('guard_name', 'web')->orderBy('name')->get();
        $grouped = $allPermissions->groupBy(fn ($p) => explode('.', $p->name)[0]);

        $checkboxes = [];
        foreach ($grouped as $resource => $perms) {
            $checkboxes[] = Forms\Components\Section::make(static::resourceLabel($resource))
                ->collapsed(false)
                ->schema([
                    Forms\Components\CheckboxList::make("perm_{$resource}")
                        ->label('')
                        ->options($perms->pluck('name', 'id')
                            ->mapWithKeys(fn ($name, $id) => [$id => static::actionLabel($name)])
                            ->all())
                        ->columns(4)
                        ->bulkToggleable(),
                ]);
        }

        return $form->schema([
            Forms\Components\Section::make('基本信息')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('角色 Key（英文）')
                        ->required()
                        ->maxLength(60)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn ($record) => $record?->name === 'super-admin')
                        ->helperText('小写英文，如 editor / moderator'),
                    Forms\Components\TextInput::make('guard_name')
                        ->label('Guard')
                        ->default('web')
                        ->disabled()
                        ->dehydrated(true),
                ]),
            Forms\Components\Section::make('权限')
                ->description('勾选后保存生效；super-admin 不需要勾，自动拥有全部')
                ->schema($checkboxes),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('角色 Key')->searchable(),
                Tables\Columns\TextColumn::make('users_count')
                    ->counts('users')
                    ->label('用户数'),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label('权限数'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('创建时间')
                    ->dateTime('Y-m-d H:i'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Role $r) => $r->name !== 'super-admin'),
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
            'index'  => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit'   => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    /* -------------------- 权限 label 美化 -------------------- */

    private static function resourceLabel(string $key): string
    {
        return match ($key) {
            'user'           => '👤 用户',
            'role'           => '🛡 角色',
            'long_video'     => '🎬 长视频',
            'short_video'    => '📱 短视频',
            'long_category'  => '🗂 长视频分类',
            'short_category' => '🗂 短视频分类',
            'comment'        => '💬 评论',
            'chat_room'      => '💭 聊天室',
            'chat_message'   => '✉️ 聊天消息',
            default          => $key,
        };
    }

    private static function actionLabel(string $perm): string
    {
        $action = explode('.', $perm)[1] ?? '';
        return match ($action) {
            'view'   => '查看',
            'create' => '新建',
            'update' => '编辑',
            'delete' => '删除',
            default  => $action,
        };
    }
}
