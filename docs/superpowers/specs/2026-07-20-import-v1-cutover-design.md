# Impor Data SiMAPA v1 → v2 (Cutover Final) — Design

Tanggal: 2026-07-20
Status: Disetujui untuk implementasi

## Tujuan

Memindahkan **seluruh data bisnis riil** dari SiMAPA v1 (dump `avidpedi_simapa.sql`,
di-generate 2026-07-20) ke database project v2 (`avidpedi_simapa` lokal), tanpa ada
data yang terlewat/miss, sebagai **cutover satu kali** (v1 ditinggalkan setelah ini).

Tantangan inti: v1 hanya punya ~14 tabel (order/author/pembayaran/invoice mentah),
sedangkan v2 sudah menambah kolom pada tabel inti **dan** punya layer turunan yang
dibangun *dari* data itu (Keuangan/Kas, Title Directory, Title Progress, dsb.). "Tidak
ada yang miss" karena itu berarti: impor data mentah **plus** regenerasi layer turunan
memakai logika v2 sendiri.

## Keputusan (final)

1. **Kondisi DB v2 target**: reset total. Bangun ulang fresh dari data v1. Tidak ada
   penggabungan/merge, tidak ada risiko bentrok ID.
2. **Frekuensi**: sekali jalan (cutover final). Tidak perlu mekanisme sinkron berulang.
3. **Kedalaman**: penuh — base + semua layer turunan diregenerasi via service v2.
4. **Password 5 user internal**: pertahankan **hash asli v1** (login lama tetap jalan).
5. **`title_progress`**: semua judul dimulai di **tahap awal** (`menunggu_proses`),
   termasuk order yang sudah `lunas` — karena `lunas` adalah status *pembayaran*, bukan
   status *produksi*, jadi tahap produksi tak bisa disimpulkan dari status bayar.

## Non-Goals

- Bukan mekanisme sinkron dua-arah atau berkala.
- Tidak mengimpor pengeluaran/refund keuangan (di v1 memang belum ada; keadaan kas v2
  sengaja: pengeluaran 0, refund 0). Impor CSV pengeluaran adalah pekerjaan terpisah
  yang sedang ditunda user.
- Tidak mengarang riwayat tahap produksi/manuskrip yang tidak ada sumbernya di v1.

## Arsitektur

Satu **Artisan command**: `php artisan simapa:import-v1 [--force]`.

Alasan memilih command (bukan sekadar memperbaiki `ProductionDataSeeder`): cutover butuh
orkestrasi lintas langkah (`migrate:fresh` → seed → impor → backfill) dan **ringkasan
verifikasi** di akhir. Satu command membuat proses reproducible & mudah diaudit.

Sumber data: file `avidpedi_simapa.sql` di root project (`base_path('avidpedi_simapa.sql')`).
Metode baca: eksekusi statement `INSERT` literal dari dump via `DB::unprepared` (byte-for-byte;
unicode, escape, dan `\r\n` di dalam note terjaga persis seperti v1). Koneksi memakai
charset `utf8mb4`.

> Catatan: `ProductionDataSeeder.php` yang ada sekarang adalah cikal-bakal yang **usang &
> bercacat** (meng-truncate roles lalu impor 5 role dari dump → menghapus role `accounting`;
> AUTO_INCREMENT hardcode yang sudah basi; tidak meregenerasi Kas & Title Directory).
> Command ini menggantikannya. Seeder lama boleh dihapus atau dibiarkan (tidak dipakai).

## Alur Command (berurutan)

1. **Konfirmasi destruktif** — peringatkan bahwa seluruh DB v2 akan dihapus. Lanjut hanya
   bila user mengetik konfirmasi atau memberi `--force`.
2. **`migrate:fresh`** — skema v2 lengkap. Migrasi backfill (title/cash) berjalan di tabel
   kosong = no-op aman. Migrasi `add_accounting_role` membuat role `accounting`.
3. **Seed `PermissionSeed`** — 11 permission, 5 role (marketing/superadmin/manager/
   production/admin), 5 user + profil. Karena user dibuat berurutan pada DB fresh, ID user
   1–5 cocok dengan dump (super=1, fitri=2, nurul=3, ika=4, pia=5), dan pemetaan role→user
   identik dengan dump.
4. **Impor 10 tabel bisnis** dari dump, urut aman-FK (parent dulu):
   `tb_scopes → tb_authors → tb_orders → tb_order_contacts → tb_order_details →
   tb_scope_orders → tb_author_orders → tb_payments → tb_payment_approvals → tb_invoices`.
   Setiap INSERT dump menyebut kolomnya eksplisit, sehingga kolom baru v2 yang nullable
   (`group_key`, `title_id`, `refund_*`, `type`, dsb.) aman terlewat/terisi default.
   Dijalankan dengan `SET FOREIGN_KEY_CHECKS=0` selama impor.
5. **Rekonsiliasi 5 user** — untuk tiap user 1–5, timpa `password` (hash bcrypt asli v1),
   `remember_token`, `email_verified_at`, `created_at`, `updated_at` dari dump. Username/
   email/role tetap dari `PermissionSeed` (sudah identik).
6. **Fix invoices** — set `type='regular'` untuk semua; ubah `status` `pending → diterbitkan`
   (persis perilaku migrasi `2026_06_13_000003_update_tb_invoices_table`).
7. **`TitleBackfillService::run()`** — bikin `tb_titles`, set `title_id` + `group_key`
   (via hook `OrderDetail::saving`) + `code` (via `TitleCodeService`) untuk semua order detail.
8. **`PaymentCashBackfillService::run()`** — bangkitkan entri kas pemasukan dari payment
   `status='paid'` (idempotent, `updateOrCreate` per payment_id; payment `rejected` otomatis
   tidak ikut). Guard saldo-awal lolos karena DB fresh (saldo awal 0).
9. **Generate layer turunan sisa**:
   - `tb_title_progress`: 1 baris per `order_detail`, status `menunggu_proses`,
     `assigned_role='marketing'`, `updated_by=1`, `started_at=created_at` detail.
   - `tb_invoice_logs`: 1 entri awal per invoice — `from_status=''`, `to_status='diterbitkan'`,
     `changed_by=1`, note "Import data produksi", `created_at=created_at` invoice.
10. **Set AUTO_INCREMENT dinamis** — untuk tiap tabel ter-impor + turunan, `ALTER TABLE …
    AUTO_INCREMENT = MAX(id)+1` (dihitung dari data, bukan angka hardcode).
11. **Ringkasan verifikasi** — cetak tabel jumlah baris (scopes, authors, orders,
    order_details, payments, invoices, dst.) + hasil backfill (titles dibuat, cash entries
    ter-sync, title_progress, invoice_logs). Tandai bila ada anomali (mis. jumlah invoice ≠
    jumlah invoice_logs).

## Penanganan Delta Skema (mentah v1 → v2)

| Tabel | Delta v2 | Penanganan |
|---|---|---|
| `tb_order_details` | +`group_key`, +`title_id` (nullable) | Terisi oleh `TitleBackfillService` (langkah 7). |
| `tb_payments` | +`refund_reason/method/account`, +`refunded_by` (nullable) | Dibiarkan NULL (v1 tak punya refund). |
| `tb_invoices` | +`type`,`note`,`cancelled_*`,`refunded_*`; status `pending→diterbitkan` | Langkah 6. |
| `roles` | +`accounting` (dari migrasi) | Tidak diimpor dari dump; dibuat migrasi + `PermissionSeed`. |

## Tabel yang TIDAK diimpor (sengaja)

`failed_jobs`, `jobs`, `migrations`, `password_reset_tokens`, `personal_access_tokens`,
`model_has_permissions`, `model_has_roles`, `roles`, `permissions`, `role_has_permissions`,
`users`, `user_profiles` (dari dump) — infrastruktur atau sudah ditangani `PermissionSeed`
+ migrasi (kecuali kredensial user yang direkonsiliasi di langkah 5).

## Verifikasi (kriteria sukses)

Setelah command selesai, harus benar:
- Jumlah baris tabel bisnis cocok dump: scopes 102, authors 173, orders 126 (id 4–129),
  order_details 126, order_contacts 126, payments 178 (id 4–182, id 136 kosong),
  payment_approvals 178, invoices 178 (id 4–182, id 136 kosong), scope_orders &
  author_orders sesuai dump.
- Setiap `order_detail` punya `title_id` non-null & `group_key` non-null; `tb_titles` terisi.
- Jumlah cash entries = jumlah payment `paid` (bukan `rejected`).
- Setiap invoice punya minimal 1 `invoice_log`.
- Login sebagai `super` (superadmin@avidpedia.com) dengan password v1 berhasil.
- Halaman inti tampil tanpa 500: daftar Order, Arsip Judul/Title Directory, Keuangan/Jurnal
  Kas, Dashboard.
- `AUTO_INCREMENT` tiap tabel = MAX(id)+1 (buat order baru tidak bentrok ID).

## Risiko & Mitigasi

- **Kolom baru non-nullable tanpa default** akan menggagalkan raw INSERT → verifikasi
  saat implementasi bahwa `group_key`/`title_id`/`deleted_at`/refund fields memang nullable
  (dikonfirmasi nullable dari migrasi). Kalau ada yang tidak, tangani eksplisit.
- **Mojibake di dump** (mis. `Indonesiaâs`) sudah ada di v1; raw INSERT mempertahankan byte
  apa adanya → tidak menambah korupsi, setia pada sumber.
- **Idempotensi**: command aman dijalankan ulang karena diawali `migrate:fresh` (state
  direset total tiap run). Cocok untuk iterasi saat pengembangan.
- **File dump wajib ada** di root; command gagal keras dengan pesan jelas bila tak ditemukan.

## Rollback

Tidak ada rollback khusus: karena command mulai dari `migrate:fresh`, menjalankan ulang =
membangun ulang dari nol. Untuk membatalkan seluruhnya, jalankan `migrate:fresh` + seed
normal tanpa command ini.
