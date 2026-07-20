<?php
// app/Support/PermissionMap.php

namespace App\Support;

class PermissionMap
{
    /** @return array<string,string> nama route => nama permission */
    public static function routeToPermission(): array
    {
        static $flat = null;
        if ($flat !== null) {
            return $flat;
        }

        $flat = [];
        foreach (config('permissions.modules', []) as $module => $def) {
            foreach ($def['actions'] ?? [] as $action => $routes) {
                foreach ($routes as $routeName) {
                    $flat[$routeName] = $module . '.' . $action;
                }
            }
        }

        return $flat;
    }

    /** Semua nama permission yang dikenal sistem. */
    public static function allPermissions(): array
    {
        return array_values(array_unique(array_values(self::routeToPermission())));
    }

    public static function isPublic(string $routeName): bool
    {
        return in_array($routeName, config('permissions.public', []), true);
    }

    /** null = route tidak terpeta (middleware menolaknya: fail-closed). */
    public static function permissionFor(string $routeName): ?string
    {
        return self::routeToPermission()[$routeName] ?? null;
    }

    /** Untuk UI: [modul => ['label' => ..., 'actions' => [aksi => permission]]] */
    public static function matrix(): array
    {
        $out = [];
        foreach (config('permissions.modules', []) as $module => $def) {
            $actions = [];
            foreach (array_keys($def['actions'] ?? []) as $action) {
                $actions[$action] = $module . '.' . $action;
            }
            $out[$module] = ['label' => $def['label'] ?? $module, 'actions' => $actions];
        }

        return $out;
    }
}
