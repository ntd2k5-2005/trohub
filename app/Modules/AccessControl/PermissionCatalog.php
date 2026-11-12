<?php

namespace App\Modules\AccessControl;

use App\Modules\AccessControl\Models\Permission;

final class PermissionCatalog
{
    public function grouped(array $options): array
    {
        return Permission::with('categoryRecord')->whereIn('code', array_keys($options))->where('active', true)->get()
            ->groupBy(fn ($p) => $p->categoryRecord?->name ?? 'Quyền khác')
            ->map(fn ($items, $name) => ['name' => $name, 'permissions' => $items->map(fn ($p) => ['code' => $p->code, 'name' => $options[$p->code]])->values()->all()])
            ->values()->all();
    }
}
