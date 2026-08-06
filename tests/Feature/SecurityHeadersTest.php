<?php
// tests/Feature/SecurityHeadersTest.php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aplikasi ini sebelumnya tidak mengirim SATU PUN header keamanan. Header
 * berikut adalah pertahanan berlapis untuk isi yang lolos penyaringan
 * ([[HtmlSanitizer]]) dan penutup clickjacking.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function halaman_login_mengirim_header_keamanan(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /** @test */
    public function csp_mengunci_frame_base_dan_objek(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp, 'CSP harus dikirim.');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    /** @test */
    public function halaman_terautentikasi_juga_dilindungi(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    /** @test */
    public function hsts_hanya_dikirim_lewat_https(): void
    {
        $this->assertNull(
            $this->get('/login')->headers->get('Strict-Transport-Security'),
            'HSTS lewat HTTP percuma dan bisa mengunci domain dev.'
        );

        $this->assertNotNull(
            $this->get('https://localhost/login')->headers->get('Strict-Transport-Security'),
            'HSTS wajib ada saat diakses lewat HTTPS.'
        );
    }
}
