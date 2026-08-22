<?php
// database/migrations/2026_08_23_000003_pulihkan_innodb_tabel_layanan.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mengembalikan enam tabel modul Layanan/Jasa ke InnoDB beserta 12 foreign key-nya.
 *
 * Di produksi (`avidpedi_simapa128`, dump 2026-08-23) keenam tabel itu bermesin MyISAM,
 * sementara 62 tabel lain InnoDB. MyISAM menerima sintaks FOREIGN KEY lalu MEMBUANGNYA
 * tanpa bersuara, jadi tak satu pun dari 12 constraint yang ditulis migrasi 2026-08-11
 * benar-benar ada di sana. Tak ada galat yang pernah muncul; hanya jaminannya yang tak
 * pernah lahir. Di dev keenamnya InnoDB — jadi ini murni penyimpangan produksi.
 *
 * Dua akibatnya nyata, dan yang kedua lebih berbahaya karena tak terlihat:
 *
 *   1. Tanpa FK, baris yatim mungkin: hapus satu klien, invoice-nya tetap hidup
 *      menunjuk ke ketiadaan.
 *   2. MyISAM tak mengenal transaksi. `DB::transaction()` di ServiceInvoiceController,
 *      ServiceInvoicePaymentController, dan ServiceClientController karena itu HANYA
 *      HIASAN di produksi: bila penyimpanan invoice gagal di tengah, itemnya sudah
 *      terlanjur tersimpan dan tak ada yang menariknya kembali.
 *
 * Tak ada data yang dihapus di sini. Bila ditemukan baris yatim pada kolom yang tak
 * boleh kosong, migrasi ini BERHENTI dengan pesan jelas alih-alih membuang barisnya —
 * keputusan membuang data bukan milik migrasi.
 *
 * Memakai DB::statement(), BUKAN Schema::table()->foreign(): Laravel akan mencoba
 * membuat ulang constraint yang mungkin sudah ada, dan di sini idempotensi lebih
 * penting daripada keanggunan. Migrasi ini harus aman dijalankan di dev (yang sudah
 * benar) maupun di produksi (yang belum).
 */
return new class extends Migration
{
    /** Urutan penting: induk sebelum anak. */
    private const TABEL = [
        'tb_service_catalogs',
        'tb_service_clients',
        'tb_service_invoices',
        'tb_service_invoice_items',
        'tb_service_invoice_payments',
        'tb_service_invoice_logs',
    ];

    /** [tabel, kolom, tabel induk, aksi saat induk dihapus] */
    private const FK = [
        ['tb_service_clients',          'created_by',         'users',               'SET NULL'],
        ['tb_service_clients',          'updated_by',         'users',               'SET NULL'],
        ['tb_service_invoices',         'service_client_id',  'tb_service_clients',  'SET NULL'],
        ['tb_service_invoices',         'cancelled_by',       'users',               'SET NULL'],
        ['tb_service_invoices',         'created_by',         'users',               'SET NULL'],
        ['tb_service_invoices',         'updated_by',         'users',               'SET NULL'],
        ['tb_service_invoice_items',    'service_invoice_id', 'tb_service_invoices', 'CASCADE'],
        ['tb_service_invoice_items',    'service_catalog_id', 'tb_service_catalogs', 'SET NULL'],
        ['tb_service_invoice_payments', 'service_invoice_id', 'tb_service_invoices', 'CASCADE'],
        ['tb_service_invoice_payments', 'created_by',         'users',               'SET NULL'],
        ['tb_service_invoice_logs',     'service_invoice_id', 'tb_service_invoices', 'CASCADE'],
        ['tb_service_invoice_logs',     'changed_by',         'users',               'SET NULL'],
    ];

    public function up(): void
    {
        // 1. Semua ke InnoDB LEBIH DULU. MariaDB menolak constraint yang menunjuk
        //    tabel MyISAM, jadi mengubah mesin sambil memasang FK per tabel akan
        //    gagal pada tabel anak yang induknya belum sempat dikonversi.
        foreach (self::TABEL as $tabel) {
            if ($this->mesin($tabel) !== 'InnoDB') {
                DB::statement("ALTER TABLE `{$tabel}` ENGINE=InnoDB");
            }
        }

        // 2. Pasang FK yang belum ada.
        foreach (self::FK as [$tabel, $kolom, $induk, $aksi]) {
            $nama = "{$tabel}_{$kolom}_foreign";

            if ($this->punyaConstraint($nama)) {
                continue;
            }

            $this->pastikanTakAdaYatim($tabel, $kolom, $induk, $aksi);

            DB::statement(
                "ALTER TABLE `{$tabel}` ADD CONSTRAINT `{$nama}` "
                . "FOREIGN KEY (`{$kolom}`) REFERENCES `{$induk}` (`id`) ON DELETE {$aksi}"
            );
        }
    }

    /**
     * Baris yatim menghalangi pemasangan FK. Pada kolom yang boleh kosong, penunjuk ke
     * induk yang sudah tiada memang sudah tak bermakna — dikosongkan, dan itu persis
     * yang akan dilakukan SET NULL seandainya constraint-nya ada sejak dulu.
     *
     * Pada kolom CASCADE (tak boleh kosong) tak ada tindakan yang tidak menghapus data,
     * jadi migrasi berhenti dan menyerahkan keputusannya ke manusia.
     */
    private function pastikanTakAdaYatim(string $tabel, string $kolom, string $induk, string $aksi): void
    {
        $yatim = DB::table($tabel)
            ->whereNotNull($kolom)
            ->whereNotIn($kolom, fn ($q) => $q->select('id')->from($induk))
            ->count();

        if ($yatim === 0) {
            return;
        }

        if ($aksi === 'SET NULL') {
            DB::table($tabel)
                ->whereNotNull($kolom)
                ->whereNotIn($kolom, fn ($q) => $q->select('id')->from($induk))
                ->update([$kolom => null]);

            return;
        }

        throw new RuntimeException(
            "{$tabel}.{$kolom} punya {$yatim} baris yatim yang menunjuk {$induk} tak ada. "
            . 'Constraint-nya CASCADE, jadi tak ada jalan memasangnya tanpa menghapus baris itu. '
            . 'Periksa datanya dan putuskan sendiri sebelum menjalankan migrasi ini lagi.'
        );
    }

    private function mesin(string $tabel): ?string
    {
        return DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tabel)
            ->value('ENGINE');
    }

    private function punyaConstraint(string $nama): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', $nama)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    /**
     * Sengaja tanpa isi. Memutar balik berarti mengembalikan tabel ke MyISAM — yaitu
     * membuang lagi jaminan yang baru saja dipulihkan. Tak ada keadaan di mana itu
     * yang benar-benar diinginkan.
     */
    public function down(): void
    {
        //
    }
};
