<?php
// tests/Feature/PermissionMapCompletenessTest.php

namespace Tests\Feature;

use App\Support\PermissionMap;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionMapCompletenessTest extends TestCase
{
    /**
     * Route yang memang di luar kendali hak akses aplikasi: auth bawaan, debug, dan
     * halaman tanpa nama. Ditulis sebagai prefix.
     */
    private array $ignoredPrefixes = [
        'login', 'logout', 'register', 'password.', 'verification.',
        'sanctum.', 'ignition.', 'livewire.',
    ];

    private function isIgnored(string $name): bool
    {
        foreach ($this->ignoredPrefixes as $p) {
            if ($name === rtrim($p, '.') || str_starts_with($name, $p)) {
                return true;
            }
        }
        return false;
    }

    /** @test */
    public function setiap_route_bernama_terpeta_atau_public(): void
    {
        $unmapped = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || $this->isIgnored($name)) {
                continue;
            }
            if (PermissionMap::isPublic($name) || PermissionMap::permissionFor($name) !== null) {
                continue;
            }
            $unmapped[] = $name;
        }

        sort($unmapped);
        $this->assertSame([], $unmapped,
            "Route berikut belum ada di config/permissions.php (public atau modules):\n  - "
            . implode("\n  - ", $unmapped));
    }

    /** @test */
    public function tidak_ada_route_yang_sekaligus_public_dan_berizin(): void
    {
        $both = array_intersect(config('permissions.public'), array_keys(PermissionMap::routeToPermission()));
        $this->assertSame([], array_values($both),
            'Route ini terdaftar public sekaligus punya permission: ' . implode(', ', $both));
    }
}
