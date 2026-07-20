<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Smoke test mendalam untuk GET route ber-parameter, dijalankan di atas salinan
 * data nyata (avidpedi_simapa_test yang di-restore dari dev). TIDAK memakai
 * RefreshDatabase supaya datanya utuh. Hanya request GET (tak mengubah data).
 *
 * 404 dianggap wajar (data tak ada / di luar scope role). Yang dicari: 5xx.
 *
 * @group smoke-real-data
 */
class DeepRouteSmokeTest extends TestCase
{
    /** Butuh layanan eksternal / token bertanda tangan — di luar cakupan smoke. */
    private const SKIP = [
        'profile.image',        // Google Drive
        'data.download',        // Google Drive
        'password.reset',       // butuh token reset
        'verification.verify',  // butuh signed URL
    ];

    private function candidatesFor(string $param): array
    {
        if ($param === 'code_order') {
            return DB::table('tb_orders')->orderBy('id')->limit(3)->pluck('code_order')->all();
        }

        return [1, 2, 3];
    }

    /** @test */
    public function parameterized_get_routes_render_without_server_error(): void
    {
        if (! \Schema::hasTable('tb_orders') || DB::table('tb_orders')->count() === 0) {
            $this->markTestSkipped(
                'Butuh salinan data nyata di avidpedi_simapa_test. Restore dulu: '
                . 'mysqldump avidpedi_simapa | mysql avidpedi_simapa_test'
            );
        }

        $superadmin = User::whereHas('roles', fn ($q) => $q->where('name', 'superadmin'))->first();
        $marketing  = User::whereHas('roles', fn ($q) => $q->where('name', 'marketing'))->first();
        if (! $superadmin) {
            $this->markTestSkipped('User superadmin tak ada di DB test — restore dev snapshot dulu.');
        }

        $actors = array_filter(['superadmin' => $superadmin, 'marketing' => $marketing]);
        $failures = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $name = $route->getName();
            if (! $name || str_starts_with($name, 'ignition.') || in_array($name, self::SKIP, true)) {
                continue;
            }
            $params = $route->parameterNames();
            if (empty($params) || count($params) > 1) {
                continue; // yang tanpa parameter sudah dites RouteSmokeTest.
            }

            foreach ($this->candidatesFor($params[0]) as $value) {
                $uri = str_replace('{' . $params[0] . '}', rawurlencode((string) $value), $route->uri());

                foreach ($actors as $label => $actor) {
                    $this->actingAs($actor);
                    $checked++;
                    try {
                        $status = $this->get('/' . ltrim($uri, '/'))->getStatusCode();
                    } catch (\Throwable $e) {
                        $failures[] = sprintf('%-11s %-28s %-34s EXCEPTION %s: %s',
                            $label, $name, $uri, get_class($e), \Str::limit($e->getMessage(), 160));
                        continue;
                    }
                    if ($status >= 500) {
                        $failures[] = sprintf('%-11s %-28s %-34s HTTP %d', $label, $name, $uri, $status);
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'Tak ada request yang dijalankan.');
        $this->assertSame([], $failures,
            "Route ber-parameter bermasalah ({$checked} request):\n" . implode("\n", $failures));
    }
}
