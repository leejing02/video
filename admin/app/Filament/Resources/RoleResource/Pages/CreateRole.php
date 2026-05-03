<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Permission;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** 把表单里 perm_xxx 的勾选合并成 permissions[] */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['guard_name'] = 'web';
        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncPermissionsFromForm($this->record, $this->data);
    }

    private function syncPermissionsFromForm($record, array $data): void
    {
        $ids = collect($data)
            ->filter(fn ($v, $k) => str_starts_with($k, 'perm_') && is_array($v))
            ->flatten()
            ->unique()
            ->all();
        if ($ids) {
            $perms = Permission::whereIn('id', $ids)->get();
            $record->syncPermissions($perms);
        }
    }
}
