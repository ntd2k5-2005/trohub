<?php

namespace App\Support;

use App\Support\Authorization\EffectiveAccess;
use Illuminate\Support\Collection;

final class ModuleRegistry
{
    public static function pages(): Collection
    {
        return collect(config('screens'))->filter(function ($p) {
            $feature = match ($p['_module']) {
                'analytics' => 'analytics','iot' => 'iot','roommate' => 'roommate','chat' => 'internal_chat',default => null
            };

            return is_dir(app_path('Modules/'.match ($p['_module']) {
                'iot' => 'IoT','data-platform','dataplatform' => 'DataPlatform','access-control','accesscontrol' => 'AccessControl',default => ucfirst($p['_module'])
            })) && (! $feature || config('features.'.$feature));
        })->map(fn ($p) => $p + ['_required_capability' => 'ui.'.$p['slug']])->values();
    }

    public static function modules(): Collection
    {
        return self::pages()->groupBy('_module');
    }

    public static function findPage(string $slug): ?array
    {
        return self::pages()->firstWhere('slug', $slug);
    }

    public static function hasPage(string $slug): bool
    {
        return self::findPage($slug) !== null;
    }

    public static function forContext(array $page, string $context): array
    {
        return $page;
    }

    public static function findAccessiblePage(string $slug, string $context, EffectiveAccess $access): ?array
    {
        $p = self::findPage($slug);

        return $p && in_array($context, $p['contexts'], true) && $access->can('ui.'.$slug) ? $p : null;
    }

    public static function navigation(string $context, ?EffectiveAccess $access = null): Collection
    {
        if (! $access || ! $access->canUseContext($context)) {
            return collect();
        }

        return self::pages()->filter(fn ($p) => ($p['nav'] ?? true) && in_array($context, $p['contexts'], true) && (! $access || $access->can('ui.'.$p['slug'])))->sortBy(fn ($p) => sprintf('%04d-%04d', $p['group_order'] ?? 999, $p['order'] ?? 999))->values();
    }
}
