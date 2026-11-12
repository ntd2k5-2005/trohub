<?php

namespace App\Modules\AccessControl;

final class PermissionCodes
{
    public static function expand(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            foreach ((config('authorization.legacy', [])[$code] ?? [$code]) as $leaf) {
                $out[$leaf] = true;
            }
        }

        return array_keys($out);
    }

    public static function checks(string $code): array
    {
        return config('authorization.legacy', [])[$code] ?? [$code];
    }
}
