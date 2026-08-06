<?php
// tests/Feature/RateLimitTest.php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sebelum ini HANYA halaman login yang dibatasi; 225 route web lainnya bisa
 * dipukul tanpa batas — termasuk ekspor PDF yang membangkitkan dokumen lewat
 * dompdf (sudah pernah menabrak batas 30 detik di produksi).
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function grup_web_memasang_pembatas_laju(): void
    {
        $this->assertContains(
            \Illuminate\Routing\Middleware\ThrottleRequests::class . ':web',
            app(\App\Http\Kernel::class)->getMiddlewareGroups()['web'] ?? [],
            'Grup middleware web harus membawa pembatas laju.'
        );
    }

    /** @test */
    public function lupa_password_dibatasi_agar_tak_jadi_alat_banjir_surel(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => 'orang@example.com']);
        }

        $this->post('/forgot-password', ['email' => 'orang@example.com'])
            ->assertStatus(429);
    }

    /** @test */
    public function semua_route_ekspor_dan_pdf_dibatasi(): void
    {
        $berat = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route) {
                $nama = $route->getName() ?? '';

                return str_ends_with($nama, '.pdf') || str_contains($nama, 'export');
            });

        $this->assertNotEmpty($berat, 'Route ekspor/PDF harus terdeteksi.');

        foreach ($berat as $route) {
            $this->assertContains(
                'throttle:export',
                $route->gatherMiddleware(),
                "Route {$route->getName()} membangkitkan dokumen berat dan wajib dibatasi."
            );
        }
    }
}
