<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Permission;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /** 进编辑页时把已分配的权限按 resource 前缀回填到 perm_xxx */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $assigned = $this->record->permissions->pluck('id', 'name'); // name => id
        $byResource = [];
        foreach ($assigned as $name => $id) {
            $resource = explode('.', $name)[0];
            $byResource["perm_{$resource}"][] = $id;
        }
        return array_merge($data, $byResource);
    }

    protected function afterSave(): void
    {
        $ids = collect($this->data)
            ->filter(fn ($v, $k) => str_starts_with($k, 'perm_') && is_array($v))
            ->flatten()
            ->unique()
            ->all();

        $perms = Permission::whereIn('id', $ids)->get();
        $this->record->syncPermissions($perms);
    }
}
