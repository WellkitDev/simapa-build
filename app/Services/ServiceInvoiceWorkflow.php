<?php

namespace App\Services;

use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Perpindahan status pengerjaan invoice layanan, beserta jejaknya.
 *
 * Ditaruh di Service mengikuti konvensi yang sudah hidup di codebase ini untuk
 * pola "ubah keadaan + tulis baris log": CashPeriodService::lock()/unlock() dan
 * TitleProgressService::log(). Model tetap sekadar rekaman.
 *
 * Gerbang "siapa boleh" TIDAK ada di sini — itu urusan permission di rute.
 */
class ServiceInvoiceWorkflow
{
    /** Status yang boleh dimasuki lewat changeStatus(). 'batal' sengaja di luar. */
    public const CHANGEABLE = ['belum', 'proses', 'selesai'];

    /**
     * Pindahkan status pengerjaan dan catat jejaknya. Transisi bebas antara
     * belum/proses/selesai — pekerjaan jasa rutin kembali ke Proses karena revisi
     * klien, dan memaksa satu arah cuma membuat operator berbohong.
     *
     * Pembatalan TIDAK lewat sini: 'batal' keadaan terminal yang butuh alasan.
     * Lihat cancel() (ditambahkan di Task 10).
     *
     * DUA HAL YANG PERLU DIINGAT PEMANGGIL:
     *  - `refresh()` di bawah MEMBUANG perubahan yang masih menggantung di memori
     *    pada instance yang dioper. Ini kebalikan dari `ServiceInvoice::recalcTotals()`,
     *    yang justru ikut menyimpan atribut kotor lain. Jangan mengoper invoice yang
     *    baru diubah tapi belum disimpan ke sini.
     *  - `refresh()` berada DI LUAR transaksi dan tanpa kunci baris, jadi ia menutup
     *    kasus instance basi (dua operator memuat halaman lalu menyimpan bergantian),
     *    bukan tulisan yang benar-benar serempak. Sisa celahnya diterima sadar —
     *    alat internal, satu-dua operator, sama seperti catatan di recalcTotals().
     *    Penutupnya kelak `lockForUpdate()` pada baris invoice di dalam transaksi.
     *
     * @return bool true bila status benar-benar berpindah; false bila sama.
     */
    public function changeStatus(ServiceInvoice $invoice, string $to, ?string $note, ?int $userId): bool
    {
        // Divalidasi DI SINI, bukan hanya di aturan `in:` milik controller. Kolomnya
        // varchar biasa, jadi basis data bukan jaring pengaman: 'Selesai' berkapital
        // akan tersimpan apa adanya dan lolos dari setiap filter, sedangkan 'batal'
        // lewat jalur ini menghasilkan invoice batal tanpa alasan/pelaku yang tak
        // bisa digerakkan lagi dari mana pun. Kedua service sejenis di codebase ini
        // (TitleProgressService, ChapterManuscriptService) juga memvalidasi di dalam.
        if (! in_array($to, self::CHANGEABLE, true)) {
            throw ValidationException::withMessages([
                'work_status' => "Status pengerjaan '{$to}' tidak dikenal. "
                    . 'Pembatalan punya jalurnya sendiri lewat cancel().',
            ]);
        }

        // Baca ulang dari basis data SEBELUM memutuskan apa pun. Instance yang dipegang
        // pemanggil bisa basi (dua operator memuat baris yang sama sebelum salah satu
        // menyimpan). Tanpa ini dua hal salah sekaligus: (1) $from di bawah bisa keliru,
        // dan (2) Eloquent's update() cuma mengirim kolom yang "dirty" RELATIF KE
        // SNAPSHOT ASLI MODEL INI, bukan relatif ke isi tabel saat ini — jadi kalau nilai
        // baru yang kita tulis (mis. work_finished_at = null) KEBETULAN sama dengan nilai
        // basi yang pertama kali dimuat model ini, kolom itu diam-diam tidak pernah masuk
        // ke SQL UPDATE sama sekali, dan tanggal selesai tulisan penulis lain bertahan.
        $invoice->refresh();

        // 'batal' adalah keadaan TERMINAL. Tanpa penjaga ini, satu-satunya yang
        // menahan pembukaan kembali invoice batal adalah pemeriksaan di controller —
        // dan service ini adalah API bersama, bukan milik satu controller. Memanggilnya
        // langsung akan memindahkan status keluar dari 'batal' sementara cancel_reason,
        // cancelled_by, dan cancelled_at tetap terisi: barisnya jadi menyangkal diri
        // sendiri. Alasan yang sama membuat $to divalidasi di sini, bukan hanya di rute.
        if ($invoice->isCancelled()) {
            throw ValidationException::withMessages([
                'work_status' => 'Invoice yang dibatalkan tidak bisa diubah statusnya.',
            ]);
        }

        $from = $invoice->work_status;
        if ($from === $to) {
            return false;
        }

        $attrs = ['work_status' => $to];

        if ($to === 'proses' && $invoice->work_started_at === null) {
            $attrs['work_started_at'] = now();
        }

        // Berkunci pada TUJUAN, bukan asal. `elseif ($from === 'selesai')` tampak
        // setara, tapi $from bisa basi: kalau dua orang memuat invoice yang sama
        // lalu yang satu menandai Selesai dan yang lain memindahkannya ke Proses,
        // $from si kedua masih 'belum' sehingga tanggal selesai milik yang pertama
        // ikut tertinggal di baris berstatus Proses. Tak ada yang memperbaikinya.
        if ($to === 'selesai') {
            $attrs['work_finished_at'] = now();
        } else {
            $attrs['work_finished_at'] = null;
        }

        // Perpindahan status dan jejaknya harus jatuh bersama: status yang berpindah
        // tanpa baris log adalah riwayat yang berbohong.
        DB::transaction(function () use ($invoice, $attrs, $from, $to, $note, $userId) {
            $invoice->update($attrs);

            $invoice->logs()->create([
                'event'       => 'status_changed',
                'from_status' => $from,
                'to_status'   => $to,
                'note'        => $note,
                'changed_by'  => $userId,
            ]);
        });

        return true;
    }

    /**
     * Batalkan invoice. Keadaan terminal: hanya bisa dimasuki, wajib beralasan.
     * Gerbang "siapa boleh" ada di permission rute (superadmin), bukan di sini.
     *
     * @return bool false bila invoice sudah dibatalkan sebelumnya.
     */
    public function cancel(ServiceInvoice $invoice, string $reason, ?int $userId): bool
    {
        if ($invoice->isCancelled()) {
            return false;
        }

        $from = $invoice->work_status;

        DB::transaction(function () use ($invoice, $reason, $userId, $from) {
            $invoice->update([
                'work_status'   => 'batal',
                'cancel_reason' => $reason,
                'cancelled_by'  => $userId,
                'cancelled_at'  => now(),
            ]);

            $invoice->logs()->create([
                'event'       => 'cancelled',
                'from_status' => $from,
                'to_status'   => 'batal',
                'note'        => $reason,
                'changed_by'  => $userId,
            ]);
        });

        return true;
    }
}
