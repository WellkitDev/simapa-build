# Desain: Idempotency Menyeluruh untuk Form Mutasi

- **Tanggal:** 2026-07-21
- **Status:** Disetujui (menunggu review spec)
- **Tujuan:** Mencegah *double-entry* akibat request yang terkirim/terproses lebih dari sekali (double-click, retry jaringan, refresh setelah POST, tab ganda, tombol "kembali" lalu submit ulang) sehingga satu maksud pengguna hanya menghasilkan satu penulisan data.

## 1. Latar belakang & masalah

Semua form aplikasi berpola sama: form Blade `POST`/`PUT`/`PATCH`/`DELETE` → controller `store()`/`update()` → `DB::transaction` → `create()`. **Tidak ada proteksi idempotency sistemik.** Guard yang ada hanya ad-hoc:

| Titik | Risiko double-entry | Guard saat ini |
|---|---|---|
| `payment.store` (Payment + Invoice + Approval) | Tinggi | **Tidak ada** — double-submit = 2 pembayaran + 2 invoice + 2 approval |
| `accounting.entry.store` & `transfer` (baris kas) | Tinggi | **Tidak ada** |
| `refund.store` (Payment refund → auto kas) | Tinggi | `alreadyRefunded()` |
| `order.book.store` (Order + Title + kas) | Sedang | Cek duplikat judul+email |
| `tagihan.store`, `title.store`, `journal.store`, `isbn.store`, dll | Sedang | Sebagian |
| `payment.approve/reject`, `invoice.updateStatus` | Rendah | Cek status |

Penyebab double-entry yang mungkin: double-click tombol, retry jaringan/browser otomatis, tekan "kembali" lalu submit ulang, refresh setelah POST (khususnya form yang `return back()` saat error, bukan murni PRG), request paralel dari tab kedua.

## 2. Keputusan desain (final)

Diputuskan bersama pengguna saat brainstorming:

1. **Cakupan:** Semua form mutasi (global). Satu mekanisme melindungi seluruh `POST/PUT/PATCH/DELETE`.
2. **Mekanisme:** Token idempotency (server-side, disimpan di tabel DB) + guard sisi klien (disable tombol submit).
3. **Perilaku saat duplikat terdeteksi:** *Short-circuit* — lewati penulisan, `redirect back` dengan flash `info` "Permintaan sudah diproses, data tidak digandakan."
4. **Sikap terhadap request tanpa token:** **Fail-open** — middleware hanya men-*dedupe* saat token hadir; request tanpa token tidak diblokir (nol risiko memutus flow AJAX/board lama). Auto-inject menstempel hampir semua form + AJAX sehingga cakupan tetap luas.

Default tambahan (dapat diubah pengguna):
- **TTL klaim = 24 jam**, dibersihkan lewat command terjadwal harian.
- **Form kritis keuangan** (`payment.store`, `refund.store`, `accounting.entry`, `accounting.transfer`, `order.book.store`) memakai token **server-side** (`@idempotent`) sebagai lapis ke-2 yang tidak bergantung pada JavaScript.
- **Kunci dedupe:** kolom `key` saja (unique index global). Karena token = UUID acak, tabrakan antar pengguna praktis nol, jadi tidak perlu memasukkan `user_id` ke kunci dedupe. `user_id` tetap disimpan untuk audit/kepemilikan (dan validasi opsional bahwa yang me-replay token adalah pemiliknya).

## 3. Arsitektur & alur data

```
Form Blade (POST/PUT/PATCH/DELETE)
   │  hidden field _idempotency_key  (di-inject otomatis, global; atau server-side utk form kritis)
   ▼
[web middleware group] → auth → access → EnforceIdempotency  ← BARU (terakhir, sebelum controller)
   │
   ├─ tidak ada token  ─────────────────────────► lanjut normal (fail-open)
   │
   └─ ada token → klaim atomik (INSERT unik ke tb_idempotency_keys, unik pada `key`)
         ├─ klaim BERHASIL (baris baru) → jalankan controller
         │        └─ setelah respons dihasilkan:
         │             ├─ sukses  → pertahankan klaim
         │             └─ gagal   → HAPUS klaim (agar user bisa submit ulang token sama)
         └─ klaim GAGAL (duplicate key) → SHORT-CIRCUIT
                  redirect()->back()->with('info', 'Permintaan sudah diproses, data tidak digandakan.')
```

**Prinsip inti — klaim tuntas hanya bila sukses.** Bila request pertama gagal (validasi/error), klaim dilepas supaya user dapat memperbaiki dan submit ulang dengan token yang sama tanpa terblokir keliru.

**Definisi "gagal"** (heuristik yang dipakai middleware untuk melepas klaim):
- Status respons HTTP `>= 400`, **atau**
- Respons redirect yang membawa session `errors` (kegagalan validasi Laravel), **atau**
- Respons redirect yang membawa flash `error` (kegagalan bisnis, mis. `back()->with('error', ...)`).

Selain itu (termasuk redirect sukses/`success`/`info` dan `2xx`) dianggap **sukses** → klaim dipertahankan.

**Urutan middleware:** `EnforceIdempotency` diletakkan **setelah** `auth` (butuh `user_id`) dan **sebelum** controller, sehingga request duplikat di-short-circuit **sebelum** efek samping mahal seperti upload struk ke Google Drive atau penulisan DB.

## 4. Komponen (unit tanggung jawab tunggal)

| Unit | Tanggung jawab | Bergantung pada |
|---|---|---|
| **Migrasi `tb_idempotency_keys`** | Skema klaim | — |
| **`App\Models\IdempotencyKey`** | Akses tabel + scope prune | tabel |
| **`App\Http\Middleware\EnforceIdempotency`** | Baca token, klaim atomik, short-circuit duplikat, lepas klaim saat gagal | model |
| **Blade `@idempotent` directive + `<x-idempotency-key>` component** | Render hidden token server-side untuk form kritis | `Str::uuid()` |
| **`public/js/idempotency.js`** (dimuat global di master) | Auto-inject hidden token ke `<form>` non-GET yang belum punya; tambah header `Idempotency-Key` ke AJAX jQuery/fetch; disable tombol submit saat kirim | — |
| **`App\Console\Commands\PruneIdempotencyKeys`** (`idempotency:prune`) | Hapus klaim > 24 jam; dijadwalkan harian | model |

### 4.1 Skema tabel `tb_idempotency_keys`

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigIncrements | PK |
| `key` | string(191) | **unique** — inti dedupe |
| `user_id` | unsignedBigInteger nullable | pemilik klaim (untuk scope/audit) |
| `method` | string(10) | POST/PUT/PATCH/DELETE (audit) |
| `path` | string(255) | path request (audit) |
| `created_at` | timestamp | untuk TTL/prune |

Catatan: cukup `created_at` (tanpa `updated_at`) karena baris klaim tidak di-update. Prune berbasis `created_at`.

## 5. Sisi klien (`public/js/idempotency.js`)

- Dimuat di `resources/views/layouts/master.blade.php` (satu titik render global untuk semua halaman terautentikasi).
- **Auto-inject + guard tombol** — pada event `submit` (bubbling, mengikuti pola listener `submit` yang sudah ada di master untuk `data-confirm`):
  - Jika form belum punya field `_idempotency_key`, sisipkan `<input type="hidden" name="_idempotency_key" value="<UUID>">` dengan `crypto.randomUUID()`.
  - **Disable tombol submit** setelah submit dimulai untuk mencegah double-click. Tombol di-*re-enable* bila submit di-cancel (mis. dialog `data-confirm` dibatalkan) agar tidak "mengunci" form.
  - Kompatibel dengan mekanisme `data-confirm` yang sudah ada (yang memanggil `form.submit()` ulang) — token & disable diterapkan pada submit final, bukan yang di-`preventDefault`.
- **AJAX** — bungkus/hook `jQuery.ajaxSend` dan (bila dipakai) `fetch` untuk menambah header `Idempotency-Key: <UUID>` pada request method non-GET yang belum punya header tersebut.
- Token yang identik dipakai ulang saat resubmit dari DOM yang sama (bfcache/tombol "kembali") sehingga tetap ter-dedupe; reload penuh menghasilkan token baru (memang dianggap maksud baru).

## 6. Sisi server (`EnforceIdempotency`)

- Terdaftar di grup middleware `web` (Laravel 10, via `app/Http/Kernel.php`).
- Lewati (pass-through) untuk method **GET/HEAD/OPTIONS**.
- Ambil token dari input `_idempotency_key` **atau** header `Idempotency-Key`. Jika kosong → pass-through (fail-open).
- **Klaim atomik** melalui `INSERT` yang mengandalkan **unique index** pada `key`:
  - Dua request paralel dengan token sama → hanya satu berhasil INSERT; yang kalah menangkap `QueryException` duplicate-key → short-circuit.
  - Tidak perlu lock manual/`SELECT ... FOR UPDATE`.
- Jika klaim gagal (token sudah ada) → short-circuit: `redirect()->back()->with('info', ...)`.
- Jika klaim berhasil → lanjutkan pipeline; setelah respons: evaluasi heuristik "gagal" (§3). Jika gagal → `IdempotencyKey::where('key', $token)->delete()`; jika sukses → biarkan.

### 6.1 Catatan konkurensi & kasus batas
- **Request pertama lambat + double-click:** request kedua menabrak duplicate-key saat klaim → short-circuit segera, sebelum upload/tulis. Request pertama selesai normal. Hasil: satu entri.
- **Request pertama gagal validasi:** klaim dilepas → resubmit token sama dengan data valid berhasil disimpan.
- **DELETE idempotent alami:** tetap dilindungi bila token hadir; fail-open bila tidak.
- **Endpoint AJAX tanpa token** (mis. `notifications.read`, `tasks.reorder`, `manuscript.move`): fail-open, tidak berubah perilaku; header otomatis menambah proteksi bila JS global aktif.

## 7. Perilaku duplikat & UX

- Short-circuit menghasilkan `redirect()->back()->with('info', 'Permintaan sudah diproses, data tidak digandakan.')`.
- Tambahkan handler `session('info')` di `master.blade.php` (Swal ikon `info`) — saat ini master hanya menangani `session('success')` dan `session('error')`.

## 8. Testing

Dijalankan terhadap DB uji `avidpedi_simapa_test` via `.env.testing` (jangan sentuh DB asli).

Feature test untuk middleware (target utama: `payment.store` atau route uji khusus):
1. **Dedupe:** POST 2× dengan `_idempotency_key` sama → hanya **1 baris** dibuat; respons ke-2 = redirect + flash `info`.
2. **Fail-open:** POST tanpa token → jalan normal (baris dibuat).
3. **Rilis klaim saat gagal:** POST gagal validasi (token T) → lalu POST dengan token T + data valid → **tersimpan** (tidak terblokir keliru).
4. **Isolasi/atomik:** dua klaim dengan key sama → hanya satu sukses (verifikasi tabel `tb_idempotency_keys` berisi 1 baris untuk key itu).
5. **Prune:** klaim ber-`created_at` > 24 jam terhapus oleh `idempotency:prune`.

Unit test kecil untuk `PruneIdempotencyKeys` (batas 24 jam) dan directive/component `@idempotent` (merender hidden field bernama `_idempotency_key`).

## 9. Rencana rollout

1. Migrasi + model + command + middleware (belum didaftarkan) + test → hijau.
2. Daftarkan middleware ke grup `web`.
3. Tambah `idempotency.js` + handler `session('info')` di master.
4. Tambah `@idempotent` pada 5 form kritis keuangan (lapis ke-2).
5. Jadwalkan `idempotency:prune` harian di `app/Console/Kernel.php`.
6. `php artisan migrate` pada DB dev `avidpedi_simapa` (bukan hanya DB test) agar aplikasi live tidak 500 karena tabel hilang.

## 10. Di luar cakupan (YAGNI)

- **Replay respons byte-for-byte** (menyimpan body respons) — tidak dibutuhkan untuk form web PRG; short-circuit + redirect info sudah cukup.
- **Unique constraint per domain** per tabel — rapuh dan tidak generik; tidak dipakai.
- Idempotency untuk API/webhook eksternal (belum ada di aplikasi ini).

## 11. Risiko & mitigasi

- **JS dinonaktifkan → sebagian form tak ter-stempel.** Mitigasi: form kritis keuangan pakai token server-side (`@idempotent`), tidak bergantung JS.
- **Heuristik "gagal" salah menilai.** Bila sebuah controller sukses tetapi mengembalikan flash `error`, klaim akan dilepas (over-release, aman: paling buruk membolehkan submit ulang). Bila gagal tetapi tanpa penanda, klaim dipertahankan (over-claim, berisiko memblokir retry sah) — karena itu heuristik mencakup status `>=400`, session `errors`, dan flash `error`.
- **Pertumbuhan tabel.** Mitigasi: prune harian 24 jam + index pada `created_at` bila diperlukan.
