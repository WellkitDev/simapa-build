<?php
// app/Http/Middleware/SecurityHeaders.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan dasar untuk seluruh respons web.
 *
 * CATATAN JUJUR soal CSP: `script-src` masih memuat 'unsafe-inline' karena
 * hampir semua halaman aplikasi ini menaruh <script> inline lewat
 * @push('custom-scripts'). Jadi CSP di sini BUKAN penangkal XSS — penangkalnya
 * adalah penyaringan di sisi masukan ([[App\Support\HtmlSanitizer]]). Yang
 * ditegakkan CSP ini adalah bagian yang tidak bisa merusak tampilan:
 * frame-ancestors, base-uri, object-src, dan form-action.
 *
 * Menghapus 'unsafe-inline' butuh memindahkan skrip inline ke berkas + nonce —
 * pekerjaan tersendiri, bukan bagian dari penambalan ini.
 */
class SecurityHeaders
{
    /** Host aset pihak ketiga yang memang dipakai layout/halaman. */
    private const CDN = 'https://cdn.jsdelivr.net';

    private const FONTS = 'https://fonts.googleapis.com https://fonts.gstatic.com https://fonts.bunny.net';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Content-Security-Policy', $this->csp());

        // HSTS hanya bermakna di atas HTTPS; mengirimnya lewat HTTP percuma dan
        // di lingkungan dev bisa mengunci domain ke skema yang belum tersedia.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function csp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' " . self::CDN,
            "style-src 'self' 'unsafe-inline' " . self::CDN . ' ' . self::FONTS,
            'font-src \'self\' data: ' . self::CDN . ' ' . self::FONTS,
            "img-src 'self' data: blob: https:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
            "form-action 'self'",
        ]);
    }
}
