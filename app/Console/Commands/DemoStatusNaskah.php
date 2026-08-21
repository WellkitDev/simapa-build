<?php
// app/Console/Commands/DemoStatusNaskah.php

namespace App\Console\Commands;

use App\Models\Author;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\OrderWithdrawalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data uji untuk layar-layar yang lahir dari sinkronisasi status order ↔ naskah.
 *
 * ALASAN ADANYA: DB dev punya 127 order tapi NOL naskah terbit, NOL refund, dan NOL
 * pembatalan — jadi "Siap Diarsipkan", tab "Ditarik", dan lencana pekerjaan semuanya
 * tampil kosong di sana. Tak ada yang bisa diperiksa dengan mata.
 *
 * Semua barisnya diberi awalan judul `[DEMO]` dan bisa dibuang lagi dengan
 * `--bersihkan`, supaya data dev yang asli tak pernah tercampur secara permanen.
 *
 * SENGAJA menulis sebagian besar keadaan secara langsung, bukan lewat
 * TitleProgressService::advance(): jalur itu memanggil Notifier, dan MAIL_MAILER=smtp
 * di dev — data uji tidak boleh mengirim email ke siapa pun. Penarikan adalah
 * pengecualian, karena OrderWithdrawalService::withdraw() tidak menotifikasi dan
 * cabang bab/penulisnya justru yang ingin dilihat.
 *
 * Judul yang tahapnya final WAJIB punya `link_terbit`. Sejak gerbang tahap akhir
 * dipasang, naskah tanpa link tak bisa lagi mencapai terbit/publish lewat UI — data demo
 * tanpa link akan menggambarkan keadaan yang mustahil, dan layar yang justru ingin
 * diperiksa akan penuh peringatan "belum diisi".
 */
class DemoStatusNaskah extends Command
{
    protected $signature = 'simapa:demo-status
                            {--bersihkan : Hapus seluruh data demo, jangan membuat yang baru}
                            {--force : Jangan tanya konfirmasi}';

    protected $description = 'Membuat data uji untuk layar status order ↔ naskah (bisa dibersihkan lagi)';

    private const PREFIX = '[DEMO] ';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Menolak berjalan di environment production.');

            return self::FAILURE;
        }

        if ($this->option('bersihkan')) {
            return $this->bersihkan();
        }

        $db = config('database.connections.' . config('database.default') . '.database');

        if (! $this->option('force') && ! $this->confirm(
            "Membuat data demo di database {$db}. Semua judulnya berawalan '" . trim(self::PREFIX) . "' dan bisa dibuang lagi dengan --bersihkan. Lanjut?"
        )) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        $this->bersihkan(diam: true);

        DB::transaction(function () {
            $this->artikelTerbitLunas();
            $this->artikelTerbitMenunggak();
            $this->bukuKolabSatuDitarik();
            $this->bukuKolabDitarikSetelahIsbn();
            $this->naskahDitarikBelumTerbit();
            $this->orderDibatalkan();
            $this->naskahMasihBerjalan();
        });

        $this->newLine();
        $this->info('Data demo dibuat. Yang bisa dilihat sekarang:');
        $this->table(['Layar', 'Yang muncul'], [
            ['/management/archive', 'Siap Diarsipkan: 1 Lunas, 1 "Kurang Rp 300.000", 1 buku "1 ditarik"'],
            ['/management/archive/{id}', 'Kartu kelayakan menyebut angka kekurangan; baris order ditarik diredupkan'],
            ['/order/book', 'Kolom Pekerjaan: Selesai, Berjalan, Ditarik, Dibatalkan + tombol Batalkan Penarikan'],
            ['/naskah/arsip?hanya=ditarik', 'Tab Ditarik berisi 2 naskah dengan alasan refundnya'],
            ['Dashboard admin', 'Ubin "Arsip Menunggu Artefak" tidak lagi 0'],
        ]);
        $this->line('Buang lagi dengan: php artisan simapa:demo-status --bersihkan');

        return self::SUCCESS;
    }

    /**
     * Hapus seluruh jejak demo. Urutannya dari anak ke induk, dan memakai forceDelete
     * supaya baris yang sudah soft-deleted (skenario order dibatalkan) ikut lenyap —
     * kalau tidak, menjalankan command ini dua kali menumpuk sampah tak terlihat.
     */
    private function bersihkan(bool $diam = false): int
    {
        $titles = Title::withTrashed()->where('title', 'like', self::PREFIX . '%')->get();
        $detailIds = OrderDetail::withTrashed()
            ->whereIn('title_id', $titles->pluck('id'))
            ->pluck('id');
        $orderIds = OrderDetail::withTrashed()->whereIn('id', $detailIds)->pluck('order_id')->unique();

        TitleProgress::withTrashed()->whereIn('order_detail_id', $detailIds)->forceDelete();
        DB::table('tb_author_orders')->whereIn('order_detail_id', $detailIds)->delete();
        OrderDetail::withTrashed()->whereIn('id', $detailIds)->forceDelete();

        DB::table('tb_cash_entries')->whereIn('payment_id',
            DB::table('tb_payments')->whereIn('order_id', $orderIds)->pluck('id'))->delete();
        DB::table('tb_payment_approvals')->whereIn('payment_id',
            DB::table('tb_payments')->whereIn('order_id', $orderIds)->pluck('id'))->delete();
        DB::table('tb_payments')->whereIn('order_id', $orderIds)->delete();
        DB::table('tb_invoices')->whereIn('order_id', $orderIds)->delete();
        Order::withTrashed()->whereIn('id', $orderIds)->forceDelete();

        foreach ($titles as $t) {
            DB::table('tb_title_chapter_authors')
                ->whereIn('title_chapter_id', DB::table('tb_title_chapters')->where('title_id', $t->id)->pluck('id'))
                ->delete();
            DB::table('tb_chapter_progress')
                ->whereIn('title_chapter_id', DB::table('tb_title_chapters')->where('title_id', $t->id)->pluck('id'))
                ->delete();
            DB::table('tb_title_chapters')->where('title_id', $t->id)->delete();
            DB::table('tb_title_archives')->where('title_id', $t->id)->delete();
        }
        DB::table('tb_authors')->where('name', 'like', self::PREFIX . '%')->delete();
        Title::withTrashed()->whereIn('id', $titles->pluck('id'))->forceDelete();

        if (! $diam) {
            $this->info('Data demo dibuang (' . $titles->count() . ' judul).');
        }

        return self::SUCCESS;
    }

    // ─── Skenario ───

    /** Layak diarsipkan, benar-benar lunas. */
    private function artikelTerbitLunas(): void
    {
        [$title, , $order] = $this->artikel('Efektivitas Pembelajaran Daring', 500_000,
            'https://jurnal.demo.test/efektivitas-pembelajaran-daring');
        $this->bayar($order, 500_000);
        $this->tahap($title, 'publish', final: true);
    }

    /** Layak dari sisi naskah, tapi masih menunggak 300rb — gerbang arsip harus menahannya. */
    private function artikelTerbitMenunggak(): void
    {
        [$title, , $order] = $this->artikel('Analisis Kebijakan Fiskal Daerah', 500_000,
            'https://jurnal.demo.test/analisis-kebijakan-fiskal');
        $this->bayar($order, 200_000, 'dp');
        $this->tahap($title, 'publish', final: true);
    }

    /**
     * Buku kolaborasi 3 bab, semuanya terbit, lalu bab 1 di-refund penuh.
     * Inilah skenario yang seluruh rancangan ini dibangun untuk melindunginya:
     * satu penulis mundur, dua sisanya harus tetap bisa diarsipkan.
     */
    private function bukuKolabSatuDitarik(): void
    {
        $title = Title::create([
            'title' => self::PREFIX . 'Metodologi Penelitian Terapan', 'jenis' => 'buku',
            'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui',
            'link_terbit' => 'https://toko.demo.test/metodologi-penelitian-terapan',
        ]);

        $progresses = $this->babBuku($title, 'editing');

        // Bab 1 mundur SELAGI masih di wilayah bab — di bawah batas ISBN, jadi bab dan
        // penulisnya benar-benar dicabut. Lewat layanan asli supaya snapshotnya tersimpan.
        $this->tarik($progresses->first(), 'Penulis mengundurkan diri sebelum layout');

        // Dua bab sisanya lanjut sampai terbit. Judulnya harus tetap bisa diarsipkan —
        // itu seluruh alasan rancangan ini ada.
        foreach ($progresses->slice(1) as $p) {
            $p->update(['status' => 'terbit', 'assigned_role' => 'admin', 'archived_at' => now()]);
            $p->orderDetail->order->update(['fulfillment_status' => 'selesai', 'completed_at' => now()]);
        }
    }

    /**
     * Cermin skenario di atas: penarikan yang datang SETELAH ISBN terdaftar. Babnya
     * TIDAK dicabut dan penulisnya tetap tercantum — bukunya sudah terlanjur dicetak
     * atas nama itu. Uang kembali, karyanya tidak bisa ditarik dari peredaran.
     */
    private function bukuKolabDitarikSetelahIsbn(): void
    {
        $title = Title::create([
            'title' => self::PREFIX . 'Statistika Terapan untuk Sosial', 'jenis' => 'buku',
            'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui',
            'link_terbit' => 'https://toko.demo.test/statistika-terapan',
        ]);

        $progresses = $this->babBuku($title, 'cetak');
        $this->tarik($progresses->first(), 'Mundur setelah buku dicetak');

        foreach ($progresses->slice(1) as $p) {
            $p->update(['status' => 'terbit', 'assigned_role' => 'admin', 'archived_at' => now()]);
            $p->orderDetail->order->update(['fulfillment_status' => 'selesai', 'completed_at' => now()]);
        }
    }

    /**
     * Tiga bab berpenulis + order + pembayaran lunas, semuanya pada $tahap.
     *
     * @return \Illuminate\Support\Collection<int,TitleProgress>
     */
    private function babBuku(Title $title, string $tahap)
    {
        $progresses = collect();

        foreach ([1, 2, 3] as $bab) {
            $chapter = $title->chapters()->create(['judul' => "Bab {$bab}", 'urutan' => $bab]);
            $author  = Author::create(['name' => self::PREFIX . 'Penulis ' . $title->id . '-' . $bab]);
            $chapter->authors()->attach($author->id, ['position' => 1]);
            $chapter->progress()->create(['status' => 'selesai', 'started_at' => now()]);

            $order  = $this->order();
            $detail = OrderDetail::create([
                'order_id' => $order->id, 'type' => 'bk_kolab',
                'title' => $title->title, 'slug' => Str::slug($title->title),
                'title_id' => $title->id, 'chapters' => $bab,
                'naskah_type' => 'dibuatkan', 'publication_type' => 'regular',
                'cost_amount' => 1_000_000,
            ]);
            $detail->authors()->attach($author->id, ['position' => 1]);
            $this->bayar($order, 1_000_000);

            $progresses->push(TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => $tahap,
                'assigned_role' => TitleProgress::getHandlerForStatus($tahap), 'bidang' => 'buku',
                'started_at' => now(),
            ]));
        }

        return $progresses;
    }

    /** Tarik satu order lewat layanan asli, supaya cabang bab/penulis benar-benar jalan. */
    private function tarik(TitleProgress $progress, string $alasan): void
    {
        $order  = $progress->orderDetail->order;
        $refund = Payment::create([
            'order_id' => $order->id, 'payment_type' => 'refund',
            'amount' => (int) $progress->orderDetail->cost_amount,
            'status' => 'paid', 'paid_at' => now(),
            'refund_reason' => $alasan,
        ]);

        app(OrderWithdrawalService::class)->withdraw($order->fresh(), $refund, $this->aktor());
    }

    /** Naskah ditarik yang BELUM terbit — pengisi tab "Ditarik" di Arsip Naskah. */
    private function naskahDitarikBelumTerbit(): void
    {
        [$title, , $order] = $this->artikel('Pemetaan Risiko Bencana Pesisir', 750_000);
        $this->bayar($order, 750_000);
        $this->tahap($title, 'editing');

        $refund = Payment::create([
            'order_id' => $order->id, 'payment_type' => 'refund', 'amount' => 750_000,
            'status' => 'paid', 'paid_at' => now(),
            'refund_reason' => 'Klien membatalkan, naskah belum jadi',
        ]);

        app(OrderWithdrawalService::class)->withdraw($order->fresh(), $refund, $this->aktor());
    }

    /** Lencana "Dibatalkan" di daftar order. Ditulis langsung — cancel() menotifikasi. */
    private function orderDibatalkan(): void
    {
        [$title, $detail, $order] = $this->artikel('Kajian Literasi Digital Guru', 400_000);
        $this->tahap($title, 'menunggu_proses');

        $order->update([
            'status' => 'dibatalkan', 'fulfillment_status' => 'dibatalkan',
            'cancel_reason' => 'Klien membatalkan sebelum proses',
            'cancelled_by' => $this->aktor()->id, 'cancelled_at' => now(),
        ]);
        TitleProgress::where('order_detail_id', $detail->id)->delete();
        $detail->delete();
        $order->delete();
    }

    /** Kontrol: naskah biasa yang masih berjalan, supaya lencananya tak semua sama. */
    private function naskahMasihBerjalan(): void
    {
        [$title, , $order] = $this->artikel('Inovasi Kurikulum Merdeka', 600_000);
        $this->bayar($order, 300_000, 'dp');
        $this->tahap($title, 'pembuatan');
    }

    // ─── Perkakas ───

    /** @return array{0: Title, 1: OrderDetail, 2: Order} */
    private function artikel(string $judul, int $biaya, ?string $linkTerbit = null): array
    {
        $title = Title::create([
            'title' => self::PREFIX . $judul, 'jenis' => 'artikel',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
            'link_terbit' => $linkTerbit,
        ]);

        $order  = $this->order();
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => $title->title, 'slug' => Str::slug($title->title), 'title_id' => $title->id,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular',
            'cost_amount' => $biaya,
        ]);

        return [$title, $detail, $order];
    }

    private function order(): Order
    {
        return Order::create([
            'code_order' => 'ORD-DEMO-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'user_id'    => $this->aktor()->id,
            'status'     => 'pending',
            'ordered_at' => now(),
        ]);
    }

    private function bayar(Order $order, int $jumlah, string $tipe = 'lunas'): void
    {
        Payment::create([
            'order_id' => $order->id, 'payment_type' => $tipe, 'amount' => $jumlah,
            'status' => 'paid', 'paid_at' => now(),
        ]);

        if ($tipe === 'lunas') {
            $order->update(['status' => 'lunas']);
        }
    }

    private function tahap(Title $title, string $status, bool $final = false): void
    {
        foreach ($title->orderDetails as $detail) {
            TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => $status,
                'assigned_role'   => TitleProgress::getHandlerForStatus($status),
                'bidang'          => $title->jenis === 'buku' ? 'buku' : 'artikel',
                'started_at'      => now(),
                'archived_at'     => $final ? now() : null,
            ]);

            if ($final) {
                $detail->order->update(['fulfillment_status' => 'selesai', 'completed_at' => now()]);
            }
        }
    }

    private ?User $aktor = null;

    /** Superadmin mana pun — dipakai sebagai pelaku pada log penarikan. */
    private function aktor(): User
    {
        return $this->aktor ??= User::whereHas('roles', fn ($q) => $q->where('name', 'superadmin'))
            ->firstOrFail();
    }
}
