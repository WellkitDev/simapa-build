<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Smoke test: setiap GET route tanpa parameter dibuka oleh setiap role.
 * Tujuannya menangkap 500 (bukan 403/302) di halaman yang belum punya test sendiri,
 * termasuk error saat data masih kosong (null, bagi nol, dsb).
 */
class RouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['superadmin', 'manager', 'admin', 'marketing', 'production', 'accounting'];

    /** Route yang sengaja dilewati: keluar dari sesi / bukan halaman aplikasi. */
    private const SKIP = ['logout', 'ignition.healthCheck'];

    protected function setUp(): void
    {
        parent::setUp();

        // Test ini menembak ratusan route dalam satu metode — pola lalu lintas
        // yang memang dimaksudkan dicegat `throttle:web`. Tanpa dikecualikan,
        // sisa route-nya cuma menerima 429 dan smoke test-nya berhenti
        // menguji apa pun tanpa pernah memerah (ambangnya >= 500).
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        foreach (self::ROLES as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** @return list<array{string,string}> nama + uri */
    private function parameterlessGetRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $name = $route->getName();
            if (! $name || in_array($name, self::SKIP, true)) {
                continue;
            }
            if (str_starts_with($name, 'ignition.')) {
                continue;
            }
            if (! empty($route->parameterNames())) {
                continue; // butuh data; di luar cakupan smoke test ini.
            }
            $out[] = [$name, $route->uri()];
        }

        return $out;
    }

    /** @test */
    public function every_parameterless_get_route_renders_without_server_error(): void
    {
        $routes = $this->parameterlessGetRoutes();
        $this->assertNotEmpty($routes, 'Tidak ada route yang terkumpul — enumerasi gagal.');

        $failures = [];

        foreach (self::ROLES as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            foreach ($routes as [$name, $uri]) {
                $this->actingAs($user);
                try {
                    $status = $this->get('/' . ltrim($uri, '/'))->getStatusCode();
                } catch (\Throwable $e) {
                    $failures[] = sprintf(
                        "%-12s %-40s EXCEPTION %s: %s",
                        $role, $name, get_class($e), $e->getMessage()
                    );
                    continue;
                }
                if ($status >= 500) {
                    $failures[] = sprintf('%-12s %-40s HTTP %d', $role, $name, $status);
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Route bermasalah:\n" . implode("\n", $failures)
        );
    }
}
