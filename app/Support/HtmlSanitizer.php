<?php
// app/Support/HtmlSanitizer.php

namespace App\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Penyaring HTML berbasis ALLOWLIST untuk isi yang dirender mentah ({!! !!}).
 *
 * Dipakai isi pengumuman, yang tampil di dashboard SEMUA user termasuk
 * superadmin. Penyaring sebelumnya hanya regex penghapus pasangan
 * <script>/<style> — dilewati sepele oleh `<img onerror>`, `<svg onload>`,
 * `<iframe srcdoc>`, bahkan `<script src=...>` tanpa tag penutup.
 *
 * Prinsipnya: apa pun yang tidak terdaftar dibuang. Tag yang isinya adalah
 * KODE (script/style) dibuang beserta isinya; tag lain yang tak dikenal
 * dilepas bungkusnya saja supaya teks pengguna tidak ikut hilang.
 *
 * Daftar tag/atribut sengaja mengikuti keluaran Summernote (editor yang
 * dipakai form pengumuman) agar penyaringan tidak mematikan fiturnya:
 * gambar tempel data-URI dan style perataan tetap lolos.
 */
class HtmlSanitizer
{
    /** Tag yang boleh bertahan apa adanya. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'div', 'span',
        'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup', 'small', 'mark',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'a', 'img',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'col', 'colgroup',
    ];

    /** Tag yang isinya berupa kode/markup aktif: buang beserta seluruh isinya. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'label',
        'svg', 'math', 'link', 'meta', 'base', 'title', 'noscript', 'template',
    ];

    /** Atribut yang boleh bertahan, per tag. '*' berlaku untuk semua tag. */
    private const ALLOWED_ATTRS = [
        '*'   => ['style', 'class', 'title'],
        'a'   => ['href', 'target'],
        'img' => ['src', 'alt', 'width', 'height'],
        'td'  => ['colspan', 'rowspan'],
        'th'  => ['colspan', 'rowspan'],
        'col' => ['span'],
    ];

    /** Skema URL yang dianggap aman untuk href. */
    private const SAFE_HREF_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Properti CSS yang boleh bertahan di atribut style. */
    private const ALLOWED_CSS = [
        'text-align', 'text-decoration', 'font-weight', 'font-style', 'font-size',
        'color', 'background-color', 'margin', 'margin-left', 'margin-right',
        'margin-top', 'margin-bottom', 'padding', 'padding-left', 'padding-right',
        'padding-top', 'padding-bottom', 'width', 'height', 'border', 'border-collapse',
        'vertical-align', 'line-height', 'float',
    ];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $doc     = new DOMDocument();
        $sebelum = libxml_use_internal_errors(true);

        // Deklarasi XML memaksa parser membaca masukan sebagai UTF-8; tanpa itu
        // teks Indonesia beraksen rusak. NOIMPLIED/NODEFDTD mencegah libxml
        // menyuntik <html>/<body>/doctype yang tidak kita minta.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="simapa-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($sebelum);

        $root = $doc->getElementById('simapa-root') ?? $doc->getElementsByTagName('div')->item(0);
        if (! $root) {
            return '';
        }

        self::bersihkanAnak($root);

        $keluaran = '';
        foreach (iterator_to_array($root->childNodes) as $anak) {
            $keluaran .= $doc->saveHTML($anak);
        }

        // saveHTML() memulangkan karakter non-ASCII sebagai entitas numerik.
        // Hanya entitas di atas ASCII yang dipulihkan — `&lt;` dan `&#60;`
        // sengaja DIBIARKAN utuh supaya tidak menghidupkan lagi tag yang sudah
        // di-escape penulisnya.
        return trim(mb_decode_numericentity($keluaran, [0x80, 0x10FFFF, 0, 0x10FFFF], 'UTF-8'));
    }

    private static function bersihkanAnak(DOMNode $induk): void
    {
        foreach (iterator_to_array($induk->childNodes) as $anak) {
            if ($anak instanceof DOMText) {
                continue;
            }

            if ($anak instanceof DOMComment || ! $anak instanceof DOMElement) {
                $anak->parentNode->removeChild($anak);
                continue;
            }

            $tag = strtolower($anak->nodeName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $anak->parentNode->removeChild($anak);
                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                self::lepasBungkus($anak);
                continue;
            }

            self::saringAtribut($anak, $tag);
            self::bersihkanAnak($anak);
        }
    }

    /** Buang tag-nya, pertahankan isinya (yang sudah dibersihkan lebih dulu). */
    private static function lepasBungkus(DOMElement $el): void
    {
        self::bersihkanAnak($el);

        while ($el->firstChild) {
            $el->parentNode->insertBefore($el->firstChild, $el);
        }

        $el->parentNode->removeChild($el);
    }

    private static function saringAtribut(DOMElement $el, string $tag): void
    {
        $boleh = array_merge(self::ALLOWED_ATTRS['*'], self::ALLOWED_ATTRS[$tag] ?? []);

        foreach (iterator_to_array($el->attributes) as $atribut) {
            $nama = strtolower($atribut->nodeName);
            $isi  = $atribut->nodeValue ?? '';

            // Semua on* (onerror/onload/onclick/...) jatuh ke sini: tidak pernah
            // ada di allowlist, jadi ikut terbuang tanpa perlu didaftar.
            if (! in_array($nama, $boleh, true)) {
                $el->removeAttribute($atribut->nodeName);
                continue;
            }

            if ($nama === 'href' && ! self::hrefAman($isi)) {
                $el->removeAttribute($atribut->nodeName);
                continue;
            }

            if ($nama === 'src' && ! self::srcAman($isi)) {
                $el->removeAttribute($atribut->nodeName);
                continue;
            }

            if ($nama === 'style') {
                $style = self::saringStyle($isi);
                $style === '' ? $el->removeAttribute('style') : $el->setAttribute('style', $style);
            }
        }

        // Tautan keluar tidak boleh memberi akses window.opener ke halaman kita.
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }

    private static function hrefAman(string $url): bool
    {
        $uji = self::rapikanUrl($url);

        if ($uji === '') {
            return false;
        }

        if (str_starts_with($uji, '#') || str_starts_with($uji, '/')) {
            return true;
        }

        foreach (self::SAFE_HREF_SCHEMES as $skema) {
            if (str_starts_with($uji, $skema . ':')) {
                return true;
            }
        }

        // Relatif (tanpa skema) boleh; apa pun yang membawa ':' sebelum '/'
        // pertama berarti skema asing — tolak.
        $ruas = explode('/', $uji)[0];

        return ! str_contains($ruas, ':');
    }

    private static function srcAman(string $url): bool
    {
        $uji = self::rapikanUrl($url);

        // Gambar tempel Summernote. Hanya tipe gambar — `data:text/html` adalah
        // vektor XSS penuh.
        if (str_starts_with($uji, 'data:')) {
            return (bool) preg_match('#^data:image/(png|jpe?g|gif|webp|bmp);base64,#', $uji);
        }

        return self::hrefAman($url);
    }

    /**
     * Normalisasi untuk pemeriksaan skema: spasi, karakter kontrol, dan NUL
     * dipakai menyelundupkan skema (`java\tscript:`, `java\0script:`).
     */
    private static function rapikanUrl(string $url): string
    {
        return strtolower(preg_replace('/[\s\x00-\x1F\x7F]/', '', trim($url)) ?? '');
    }

    private static function saringStyle(string $style): string
    {
        $aman = [];

        foreach (explode(';', $style) as $deklarasi) {
            if (! str_contains($deklarasi, ':')) {
                continue;
            }

            [$properti, $nilai] = explode(':', $deklarasi, 2);
            $properti = strtolower(trim($properti));
            $nilai    = trim($nilai);

            if (! in_array($properti, self::ALLOWED_CSS, true)) {
                continue;
            }

            // url() memuat sumber daya luar (pelacakan/eksfiltrasi), expression()
            // adalah eksekusi skrip, '<' bisa memutus konteks atribut.
            if (preg_match('/url\s*\(|expression\s*\(|javascript:|@import|[<>\\\\]/i', $nilai)) {
                continue;
            }

            $aman[] = $properti . ': ' . $nilai;
        }

        return implode('; ', $aman);
    }
}
