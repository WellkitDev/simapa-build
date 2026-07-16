# Spec — Kunci Periode + Audit Log Jurnal Kas

- **Tanggal:** 2026-07-17
- **Branch:** `cash-period-lock`
- **Scope:** (a) Kunci periode bulanan manual oleh superadmin — memblokir mutasi **manual** pada bulan terkunci; (b) audit log setiap perubahan entri kas + kunci/buka kunci; (c) **tutup lubang**: entri `source = 'payment'` tak bisa disunting/dihapus dari Jurnal Kas.
- **Di luar scope:** double-entry/reversal; kunci periode untuk modul lain; rekonsiliasi bank; mengunci `tb_payments`.
- **Keputusan user:** kunci **manual** oleh superadmin (bukan otomatis N hari) · kunci berlaku **hanya untuk entri manual**; sinkron payment tetap tembus **tapi tercatat** · lubang entri auto ditutup di siklus ini juga.

## Konteks jujur: ini dibangun sebelum ada pemakainya

Diukur 2026-07-17: **132 entri, semuanya `source = payment`; entri manual = 0; user berakses Keuangan = 1** (superadmin); role `accounting` masih kosong. Artinya kunci periode dan audit log saat ini **menjaga sesuatu yang belum ada** dan **menjawab pertanyaan ("siapa mengubah ini?") yang jawabannya selalu "kamu"**.

Dibangun atas keputusan sadar user: pengaman disiapkan **sebelum** pencatatan pengeluaran dimulai, bukan menyusul setelah data terlanjur berantakan. Risikonya diakui: kita mendesain untuk pemakaian yang dibayangkan, bukan yang dilihat. Konsekuensinya — **jaga desain tetap kecil**; jangan menambah kemampuan yang belum ada buktinya dibutuhkan.

## Masalah 1 (nyata, terbukti): entri auto bisa dihapus lewat URL

`CashEntryController::update`/`destroy` **tidak memeriksa `source`**. View menyembunyikan tombolnya untuk entri `payment` (`journal.blade.php:183-184`, badge "⚙ auto"), tapi servernya tak menegakkan apa pun. Probe (dijalankan lalu dihapus) membuktikan: superadmin `DELETE /accounting/entry/{id}` pada entri auto → **terhapus**.

Akibatnya Jurnal Kas langsung berbeda dari `tb_payments`, **diam-diam**. Selisih 74,1jt yang baru diberantas (`fe9b99f`) bisa lahir kembali lewat pintu ini, satu entri demi satu entri. Pola yang sama dengan Gudang Data: **UI menyembunyikan, server tidak menegakkan** — bedanya di sini yang dipertaruhkan angka pembukuan.

Mengunci Juni tak ada gunanya bila entri Juni bisa dihapus satu-satu lewat URL. Karena itu ini masuk siklus yang sama, bukan ditunda.

## Masalah 2: tak ada kunci, tak ada jejak

Entri manual (yang akan segera ada, karena peringatan celah pengeluaran `1505157` menuntun ke sana) bisa disunting/dihapus permanen kapan saja tanpa jejak. Tak ada cara tahu siapa mengubah apa.

## Keputusan: kunci menjaga jalur manusia, bukan jalur otomatis

Entri `source = payment` adalah **cerminan** `tb_payments` — Jurnal Kas bukan sumber kebenarannya. Mengunci cerminan sia-sia; yang dijaga seharusnya sumbernya. Maka:

| Jalur | Dijaga kunci? | Alasan |
|---|---|---|
| `CashEntryController` (store/update/destroy/transfer) — **manusia mengetik** | **Ya** | Ini yang mengubah pembukuan atas kehendak orang |
| `PaymentObserver` → `PaymentCashSyncService::sync()` — **otomatis** | **Tidak** (tembus) | Memblokirnya = menggagalkan penyimpanan payment dgn error yang muncul jauh dari sebabnya, dan bisa memblokir refund order lama |

**Komprominya diakui terbuka:** bulan terkunci **tidak benar-benar beku** — menyunting payment lama bertanggal bulan itu tetap mengubah angkanya. Karena itu perubahan semacam ini **wajib tercatat** dengan catatan `"periode terkunci"`, supaya kompromi ini **terlihat**, bukan tersembunyi. Bila kelak ini terasa kurang, obatnya bukan mengunci cerminan, melainkan mengunci `tb_payments` — spec tersendiri.

**Penjaga diletakkan di controller, bukan di model.** Guard di model (`saving` observer) akan ikut memblokir sinkron payment — persis yang ditolak. Pemisahannya bersih: controller = jalur manusia; observer = jalur otomatis.

## 1. Skema

**`tb_cash_period_locks`** — ada barisnya berarti terkunci:

```php
$table->id();
$table->unsignedSmallInteger('year');
$table->unsignedTinyInteger('month');
$table->unsignedBigInteger('locked_by')->nullable();
$table->timestamp('locked_at');
$table->timestamps();
$table->unique(['year', 'month']);
$table->foreign('locked_by')->references('id')->on('users')->nullOnDelete();
```

**`tb_cash_logs`** — jejak perubahan:

```php
$table->id();
$table->unsignedBigInteger('cash_entry_id')->nullable(); // SENGAJA tanpa FK — lihat catatan
$table->string('action', 20);                            // created|updated|deleted|locked|unlocked
$table->unsignedBigInteger('user_id')->nullable();
$table->json('changes')->nullable();
$table->string('note')->nullable();
$table->timestamps();
$table->index('cash_entry_id');
$table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
```

> **`cash_entry_id` sengaja tanpa foreign key.** FK cascade akan menghapus log saat entrinya dihapus — menghapus bukti bersama barang buktinya. FK `nullOnDelete` pun membuang tautannya. Audit log harus **hidup lebih lama** dari baris yang dicatatnya, jadi ia menyimpan id mentah + cuplikan nilai di `changes`.
>
> **`user_id` nullable.** Sinkron dari migrasi/console tak punya user login. Ditampilkan **"sistem"** — lebih jujur daripada memaksakan user palsu demi kolom yang rapi.

## 2. Exception — `app/Exceptions/CashEntryGuardException.php`

Mengikuti pola `DataAssetAccessException` (siklus Gudang Data): pesan lewat alert, bukan 403 telanjang.

```php
namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;

class CashEntryGuardException extends Exception
{
    public static function periodLocked(int $year, int $month): self
    {
        return new self("Periode {$month}/{$year} sudah dikunci. Buka kunci dulu bila memang perlu diubah.");
    }

    public static function autoEntry(): self
    {
        return new self('Entri ini otomatis dari pembayaran dan tak bisa diubah di sini. Ubah pembayarannya — jurnal ikut menyesuaikan.');
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->with('error', $this->getMessage());
    }
}
```

`back()` (bukan redirect ke route tetap) — semua mutasi kas dikirim dari halaman Jurnal Kas dan controller-nya sudah `return back()`; pengguna tetap pada filter bulan yang sedang dilihat.

## 3. Service — `app/Services/CashPeriodService.php`

```php
public function isLocked(int $year, int $month): bool
public function assertUnlocked($tanggal): void      // throw CashEntryGuardException::periodLocked
public function lock(int $year, int $month, ?User $actor): void    // + CashLog action=locked
public function unlock(int $year, int $month, ?User $actor): void  // + CashLog action=unlocked
```

`assertUnlocked` menerima tanggal (string/Carbon) → ambil year+month → cek.

## 4. Penjaga di `CashEntryController`

| Method | Penjaga |
|---|---|
| `store` | `assertUnlocked($data['tanggal'])` |
| `transfer` | `assertUnlocked($data['tanggal'])` (menutup **kedua** baris sekaligus — dibuat dari satu tanggal) |
| `update` | **`autoEntry()` bila `source === 'payment'`**; lalu `assertUnlocked($entry->tanggal)` **dan** `assertUnlocked($data['tanggal'])` |
| `destroy` | **`autoEntry()` bila `source === 'payment'`**; lalu `assertUnlocked($entry->tanggal)` |

> **`update` memeriksa DUA tanggal** — lama dan baru. Tanpa itu, entri Juli bisa diseret ke Juni yang terkunci, atau entri Juni yang beku dikeluarkan ke Juli. Kunci yang hanya memeriksa satu sisi bukan kunci.

## 5. Log ditulis dari observer, bukan controller

`app/Observers/CashEntryObserver.php` (`created`/`updated`/`deleted`), didaftarkan di `AppServiceProvider::boot` (pola sama `PaymentObserver`).

**Kenapa observer:** ia menangkap **semua** jalur — termasuk sinkron payment yang lolos kunci. Menulis log dari controller justru melewatkan jalur otomatis itu, padahal itulah satu-satunya yang bisa mengubah bulan terkunci — persis hal yang paling perlu terekam.

- `created` → `changes` = atribut inti (tanggal, jenis, amount, keterangan, account_id).
- `updated` → `changes` = `['before' => nilai lama field yang berubah, 'after' => nilai baru]` (`getOriginal()` + `getChanges()`, buang `updated_at`).
- `deleted` → `changes` = cuplikan atribut inti (agar log tetap bermakna setelah barisnya hilang).
- `note` = `'periode terkunci'` bila periode entri itu terkunci saat perubahan terjadi (menandai pass-through sinkron payment).
- `user_id` = `Auth::id()` (boleh null).

## 6. Rute + UI

| Rute | Nama | Peran |
|---|---|---|
| `POST accounting/period/lock` | `accounting.period.lock` | **superadmin** |
| `POST accounting/period/unlock` | `accounting.period.unlock` | **superadmin** |
| `GET accounting/audit` | `accounting.audit` | superadmin\|accounting |

Kunci/buka kunci = **superadmin saja** — tindakan tata kelola, bukan pembukuan harian. `accounting` boleh melihat status & log.

- **Jurnal Kas:** badge status periode (`🔒 Terkunci — dikunci {nama}, {tgl}` / tombol `🔒 Kunci bulan ini`), hanya tampil bila filter bulan spesifik (bukan `all`) dan hanya untuk superadmin. Form kunci mengirim `year` + `month`.
- **Riwayat Perubahan** (`accounting/audit.blade.php`): DataTables (konvensi repo) — Waktu · Aksi · Entri · Pelaku (`— sistem` bila null) · Perubahan · Catatan. Filter tahun. Menu sidebar **Keuangan → Riwayat Perubahan**.

## 7. Testing — `tests/Feature/CashPeriodLockTest.php` + `tests/Feature/CashAuditLogTest.php`

**Kunci** (`CashPeriodLockTest`):
- `manual_entry_in_locked_period_is_refused` — store bulan terkunci → redirect + `session('error')`, entri **tidak** bertambah.
- `update_into_locked_period_is_refused` — entri Juli, ubah tanggal ke Juni (terkunci) → ditolak, tanggal tak berubah.
- `update_out_of_locked_period_is_refused` — entri Juni (terkunci), ubah ke Juli → ditolak.
- `destroy_in_locked_period_is_refused` — entri masih ada.
- `transfer_in_locked_period_is_refused` — **nol** baris transfer tercipta (bukan satu sisi saja).
- `unlock_restores_permission` — setelah unlock, store bulan itu berhasil.
- `only_superadmin_can_lock` — `accounting` POST lock → 403; superadmin → berhasil.
- **`payment_sync_passes_lock`** — kunci Juni, simpan payment bertanggal Juni → entri kas **tetap** tersinkron (kompromi yang disengaja) **dan** lognya bercatatan `periode terkunci`.

**Lubang entri auto** (`CashPeriodLockTest`):
- **`auto_entry_cannot_be_deleted`** — DELETE entri `source=payment` → entri **tetap ada** + `session('error')`. (Probe yang tadi gagal, kini jadi penjaga permanen.)
- **`auto_entry_cannot_be_updated`** — PUT → nilainya **tidak** berubah.

**Log** (`CashAuditLogTest`):
- `create_update_delete_are_logged` — 3 baris log, action benar, `user_id` = pelaku.
- `update_log_records_before_and_after` — `changes['before']['amount']` dan `changes['after']['amount']` benar.
- `deleted_log_survives_the_entry` — hapus entri → log `deleted` **tetap ada** dan `changes` masih memuat nominalnya (membuktikan keputusan tanpa-FK).
- `lock_and_unlock_are_logged` — action `locked`/`unlocked` + pelaku.
- `system_actor_is_null` — sinkron payment tanpa user login → `user_id` null (ditampilkan "sistem").
- `audit_page_renders` — superadmin GET `accounting.audit` → 200 + memuat entri log; `accounting` → 200; `marketing` → 403.

Regresi: suite penuh (536 + baru) hijau; `php artisan view:cache` bersih. **Bila test lama gagal karena kini ada log/penjaga baru, itu temuan — laporkan.**

## 8. Risiko

- **Observer log menambah 1 INSERT per perubahan entri** — termasuk saat sinkron payment. Dengan 132 entri & pemakaian sekarang, dampaknya nihil.
- **Backfill ulang** (`PaymentCashBackfillService`) akan melahirkan log massal. Tak masalah — memang itu perubahan nyata; dan backfill sudah jalan sekali (idempotent, `updateOrCreate` tanpa perubahan tak memicu `updated`).
- **Kunci berpori** untuk sinkron payment — disengaja, dicatat, dan ditulis terbuka di §Keputusan.
- **Menutup entri auto mengubah perilaku**: siapa pun yang selama ini (secara teori) menyunting entri auto lewat URL akan ditolak. Tak ada pemakai sah untuk itu — jurnal cuma cerminan.

## 9. Komponen

- **Baru:** migrasi `tb_cash_period_locks` + `tb_cash_logs`; model `CashPeriodLock`, `CashLog`; `app/Exceptions/CashEntryGuardException.php`; `app/Services/CashPeriodService.php`; `app/Observers/CashEntryObserver.php`; `app/Http/Controllers/Pages/CashPeriodController.php` (lock/unlock/audit); view `accounting/audit.blade.php`; test `CashPeriodLockTest`, `CashAuditLogTest`.
- **Diubah:** `CashEntryController` (4 penjaga); `AppServiceProvider` (daftarkan observer); `routes/web.php` (+3 rute); `accounting/journal.blade.php` (badge + tombol kunci); `layouts/sidebar.blade.php` (+menu Riwayat Perubahan).
- **Tak diubah:** `PaymentObserver`, `PaymentCashSyncService`, `CashRecapService`, `tb_payments`, `tb_cash_entries` (tanpa kolom baru).
