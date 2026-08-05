<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Mengunci satu bug yang pernah membuat dropdown Judul di form order tampil kosong.
 *
 * Select2 membungkus tiap elemen yang di-init dengan <span class="select2
 * select2-container ...">. Selector ".select2" polos di assets/js/select2.js karena itu
 * ikut menangkap pembungkus buatan Select2 sendiri, lalu mencoba meng-init sebuah <span>
 * sebagai dropdown — widget rusak, daftar kosong. Halaman yang meng-init Select2-nya
 * sendiri lebih dulu (partial orders/partials/title-select) kena persis di situ.
 *
 * Tidak bisa diuji lewat HTTP: HTML server-side-nya benar, kerusakannya baru terjadi di
 * browser. Jadi yang diuji di sini adalah bentuk selector-nya.
 */
class Select2InitScopeTest extends TestCase
{
    private function initScript(): string
    {
        return file_get_contents(public_path('assets/js/select2.js'));
    }

    /** @test */
    public function init_select2_dibatasi_ke_elemen_select(): void
    {
        // Sengaja mencocokkan PEMANGGILANNYA, bukan sekadar keberadaan string
        // "select.select2" di berkas — string itu juga muncul di komentar penjelas,
        // sehingga assertStringContainsString biasa akan lulus meski selectornya
        // sudah dikembalikan ke bentuk polos.
        $this->assertSame(
            1,
            preg_match_all('/\$\(\s*[\'"]select\.select2[\'"]\s*\)\s*\.select2\(/', $this->initScript()),
            'assets/js/select2.js harus memanggil $("select.select2").select2().'
        );
    }

    /** @test */
    public function tidak_ada_lagi_selector_select2_polos(): void
    {
        $js = $this->initScript();

        // Cari $(".select2") / $('.select2') yang TIDAK didahului "select".
        $this->assertSame(
            0,
            preg_match_all('/\$\(\s*[\'"]\.select2[\'"]\s*\)/', $js),
            'Selector ".select2" polos ikut menangkap <span class="select2"> buatan Select2 '
            . 'sendiri dan merusak dropdown yang sudah di-init duluan. Pakai "select.select2".'
        );
    }

    /** @test */
    public function semua_pemakaian_class_select2_di_view_ada_di_elemen_select(): void
    {
        // Penyempitan selector di atas hanya sah selama class select2 memang hanya
        // dipakai pada <select>. Kalau suatu saat dipasang di elemen lain, elemen itu
        // akan diam-diam tidak ter-init — test ini yang memberi tahu.
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            foreach (file($file->getPathname()) as $no => $line) {
                if (! preg_match('/class="[^"]*\bselect2\b[^"]*"/', $line)) {
                    continue;
                }
                // Tag pembuka <select> boleh berada di baris sebelumnya, jadi cukup
                // pastikan barisnya bukan elemen lain yang jelas-jelas bukan select.
                if (preg_match('/<(div|span|input|a|button|ul|li|p)\b[^>]*class="[^"]*\bselect2\b/', $line)) {
                    $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname())
                        . ':' . ($no + 1);
                }
            }
        }

        $this->assertSame([], $offenders,
            "Class select2 dipakai di elemen non-<select> berikut; selector select.select2 "
            . "tidak akan meng-init-nya:\n  - " . implode("\n  - ", $offenders));
    }
}
