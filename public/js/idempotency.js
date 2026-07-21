/**
 * Guard idempotency sisi klien.
 * - Stempel setiap <form> non-GET dengan hidden _idempotency_key (UUID) saat DOM siap,
 *   sehingga submit apa pun (termasuk form.submit() programatik dari data-confirm) membawanya.
 * - Nonaktifkan tombol submit setelah submit dimulai (cegah double-click); re-enable saat bfcache.
 * Server tetap fail-open: token hanya dedupe bila hadir.
 */
(function () {
    'use strict';

    function uuid() {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function isMutating(form) {
        var m = (form.getAttribute('method') || 'get').toLowerCase();
        return m !== 'get';
    }

    function stamp(form) {
        if (!isMutating(form)) return;
        if (form.querySelector('input[name="_idempotency_key"]')) return; // sudah ada (mis. @idempotent)
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_idempotency_key';
        input.value = uuid();
        form.appendChild(input);
    }

    function stampAll() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) stamp(forms[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', stampAll);
    } else {
        stampAll();
    }

    // Form yang ditambahkan dinamis: stempel saat submit (fallback).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        stamp(form);
        // Jika submit di-preventDefault (mis. dialog data-confirm yang belum dikonfirmasi),
        // JANGAN nonaktifkan tombol — kalau dibatalkan, tombol akan terkunci selamanya.
        if (e.defaultPrevented) return;
        // Nonaktifkan tombol submit setelah event ini selesai (agar nilainya tetap terkirim).
        var btns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        setTimeout(function () {
            for (var i = 0; i < btns.length; i++) btns[i].disabled = true;
        }, 0);
    }, false);

    // Kembali via tombol back (bfcache): aktifkan lagi tombol submit.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        var btns = document.querySelectorAll('button[type="submit"][disabled], input[type="submit"][disabled]');
        for (var i = 0; i < btns.length; i++) btns[i].disabled = false;
    });

    // Catatan: sengaja TIDAK menyuntik header Idempotency-Key ke AJAX. Token acak
    // per-panggilan tak memberi dedupe apa pun (kunci beda tiap kirim) tapi tetap
    // menulis+menyimpan baris klaim, sehingga aksi board (drag/drop) menggemukkan
    // tabel tanpa manfaat. Middleware tetap membaca header bila suatu saat ada kode
    // yang sengaja mengirim key stabil (fail-open).
})();
