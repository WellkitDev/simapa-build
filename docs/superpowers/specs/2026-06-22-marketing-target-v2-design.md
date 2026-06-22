# Spec — Target Marketing v2 (periode, status, komisi dibayar, scope, halaman marketing)

- **Tanggal:** 2026-06-22
- **Status:** Disetujui (siap masuk implementation plan)
- **Branch:** `target-marketing` (v1 belum di-merge; v2 dilipat ke branch ini)
- **Scope:** Memperluas fitur target marketing dari model bulanan per-individu menjadi **target berbasis rentang tanggal** dengan status aktif/berakhir, status komisi dibayar/belum, cakupan individu/semua, halaman target untuk marketing, dan tampilan periode di dashboard.
- **Di luar scope (sengaja):** pengingat **terjadwal** untuk target tertunggak (butuh cron — ditunda, sejalan dengan pengingat deadline yang ditunda di Spec B), persetujuan/approval target, perubahan retroaktif target "semua" untuk marketing yang dibuat belakangan.

---

## 1. Latar Belakang

v1 (dibangun lebih awal di sesi yang sama, belum di-merge) menyimpan target per `(user_id, year, month)`. Pengguna meminta: tahu kapan target mulai & berakhir, status expired, status komisi dibayar/belum, history target yang berakhir, cakupan individu vs semua, dan halaman target untuk marketing. v2 mengubah model menjadi rentang tanggal eksplisit dan menambah workflow status/komisi. Karena v1 belum di-merge dan tabelnya kosong, v2 mengubah skema yang sama.

## 2. Tujuan & Kriteria Sukses

1. Target punya **tgl mulai & tgl selesai** eksplisit; status **Aktif/Berakhir** diturunkan dari tanggal.
2. Admin (manager/superadmin) bisa membuat target untuk **satu marketing** atau **semua marketing** sekaligus.
3. Komisi tiap target bisa ditandai **Dibayar/Belum** (manual oleh admin), dengan jejak waktu & oleh siapa.
4. Target yang berakhir tampil di **History**; yang berakhir >7 hari & komisi belum dibayar ditandai **Tertunggak**.
5. Marketing punya **halaman Target** (berjalan + history) dan kartu dashboard menampilkan periode + status.
6. Notifikasi (via `Notifier` Spec B) saat target dibuat & saat komisi ditandai dibayar.
7. Semua perilaku tertutup test; suite tetap hijau.

---

## 3. Desain

### 3.1 Skema (ubah `tb_marketing_targets`)

Migrasi alter pada tabel v1 (kosong di dev):

**Tambah kolom:**
| Kolom | Tipe | Catatan |
|---|---|---|
| `start_date` | date | awal periode |
| `end_date` | date | akhir periode |
| `batch_id` | uuid/char(36), nullable | penanda grup untuk target "semua"; null = individu |
| `commission_paid` | boolean, default false | |
| `commission_paid_at` | datetime, nullable | |
| `commission_paid_by` | FK users, nullable, nullOnDelete | |

**Hapus:** `year`, `month`, dan unique `(user_id, year, month)`.

Tiap baris tetap **per-marketing** (`user_id`). Tidak ada unique baru (satu marketing boleh punya banyak target dengan rentang berbeda). Indeks bantu: index `(user_id, start_date, end_date)` dan `batch_id`.

Model `MarketingTarget`: fillable + casts disesuaikan (`start_date`/`end_date` → `date`, `commission_paid` → boolean, `commission_paid_at` → datetime); relasi `user()` (sudah ada) + `paidBy()` belongsTo User (`commission_paid_by`).

### 3.2 Status (diturunkan, tidak disimpan)

Untuk `today = Carbon::today()`, status ∈ **{akan_datang, aktif, berakhir}** (definit):
- **akan_datang**: `today < start_date`.
- **aktif**: `start_date ≤ today ≤ end_date`.
- **berakhir** (expired): `today > end_date`.
- **Komisi**: `commission_paid` → "Dibayar" / "Belum dibayar".
- **tertunggak** (flag visual, bukan status utama): `today > end_date + 7 hari` **dan** `commission_paid = false`. Di-highlight di halaman admin & marketing. (Tidak ada notifikasi terjadwal di v2.)

`currentForMarketing` hanya memilih target berstatus **aktif**. Realisasi dihitung atas rentang [start,end] terlepas dari status (untuk akan_datang umumnya 0).

### 3.3 Cakupan individu vs semua

- **Individu**: 1 baris, `user_id` = marketing terpilih, `batch_id` = null.
- **Semua**: saat dibuat, sistem membuat **1 baris per user role `marketing`** yang aktif saat itu (target_amount, commission_rate, start_date, end_date sama), berbagi satu `batch_id` (uuid). Di halaman admin ditampilkan sebagai satu grup "Semua Marketing (periode …)". Progres & status komisi dihitung/ditandai **per baris (per marketing)**. Marketing yang dibuat setelahnya **tidak** otomatis ikut.
- Edit/hapus target "semua": beroperasi per `batch_id` (ubah/hapus seluruh baris se-batch). Edit/hapus individu: per baris.

### 3.4 `MarketingTargetService` (diubah ke rentang tanggal)

Realisasi sebuah target = pembayaran approved milik order marketing tsb dalam rentang:
`Payment::approved()->forOrdersOf($target->user)->whereBetween('paid_at', [$target->start_date->startOfDay(), $target->end_date->endOfDay()])->sum('amount')`.

Method:
- **`progressFor(MarketingTarget $target): array`** → `target, rate, realisasi, capaian_persen, komisi, sisa` (seperti v1) + `start_date, end_date, status` (`aktif`/`berakhir`), `commission_paid` (bool), `commission_paid_at`, `tertunggak` (bool). Helper `buildProgress` diperluas.
- **`currentForMarketing(User $marketing): ?array`** → progres target **aktif** marketing (today dalam rentang). Bila >1 aktif → yang `end_date` paling dekat. Bila tak ada → null. Dipakai kartu dashboard.
- **`listForMarketing(User $marketing): Collection`** → semua target marketing + progres, untuk halaman target marketing (dipisah berjalan/berakhir di view).
- **`adminList(?string $status = null): Collection`** → semua target + progres + nama marketing + scope label, untuk halaman admin (filter status `aktif`/`berakhir`/null=semua). Realisasi dihitung per target (jumlah target ter-batas pada domain ini).
- **`createTarget(string $scope, array $userIds, int $amount, float $rate, string $start, string $end, User $actor): void`** → `scope='individual'` → buat baris untuk `$userIds` (validasi role marketing); `scope='all'` → buat 1 batch_id + 1 baris per marketing aktif. Memicu notifikasi "target dibuat" (lihat 3.8).
- **`markCommissionPaid(MarketingTarget $target, User $actor): void`** → set `commission_paid=true, commission_paid_at=now(), commission_paid_by=actor`. Memicu notifikasi "komisi dibayar". (Toggle balik opsional: `unmarkCommissionPaid`.)

### 3.5 Halaman admin "Target Marketing" (rework, manager/superadmin)

Dari grid bulanan → **daftar + form**:
- **Form Buat Target**: scope (radio Individu/Semua) → bila Individu pilih marketing (select); `target_amount`, `commission_rate`, `start_date`, `end_date` (flatpickr/date input). Validasi: end ≥ start, amount ≥ 0, rate 0–100.
- **Daftar target** (DataTables): kolom **Marketing / "Semua Marketing"** · **Periode** (mulai–selesai) · Target · Rate · Realisasi · Capaian (badge) · Komisi (Rp) · **Status** (badge Aktif/Berakhir; Tertunggak merah) · **Komisi** (badge Dibayar/Belum + tombol "Tandai dibayar") · aksi (hapus; target "semua" terhapus se-batch).
- **Filter/tab**: Berjalan / Berakhir (History) / Semua.

Routes (grup `auth`, `role:manager|superadmin`):
| Route | Nama | Aksi |
|---|---|---|
| `GET /marketing-target` | `marketing-target.index` | daftar + form (filter `?status=`) |
| `POST /marketing-target` | `marketing-target.store` | buat target (individu/semua) |
| `POST /marketing-target/{id}/paid` | `marketing-target.paid` | tandai komisi dibayar |
| `DELETE /marketing-target/{id}` | `marketing-target.destroy` | hapus (individu / se-batch bila batch) |

### 3.6 Halaman Target untuk marketing (baru)

Route `GET /target` (`marketing-target.me`), grup `auth` `role:marketing|manager|superadmin` (marketing lihat miliknya; manager/superadmin boleh juga, scoped ke diri sendiri atau diarahkan ke admin — untuk v2: tampilkan target milik user yang login). View: dua bagian **Berjalan** & **History (Berakhir)**; tiap item: periode, progress bar capaian, target/realisasi/sisa, komisi + status Dibayar/Belum, badge Tertunggak bila ada. Menu sidebar "Target Saya" untuk role marketing.

### 3.7 Kartu dashboard marketing

`MarketingDashboardService::forUser` mengganti key `target` agar memakai `currentForMarketing($user)` (target aktif). Partial menampilkan **periode (mulai–selesai)** + label status pada kartu (judul jadi "Target Berjalan"); bila tak ada target aktif → "Tidak ada target berjalan." Link "lihat semua" → `/target`.

### 3.8 Notifikasi (pakai `Notifier` Spec B)

Tambah method di `Notifier`:
- `targetAssigned(User $marketing, MarketingTarget $target, User $actor)` → ke marketing: "Target baru ditetapkan" + periode + link `/target`. Dipanggil dari `createTarget` (per marketing yang kena).
- `commissionPaid(MarketingTarget $target, User $actor)` → ke `target->user`: "Komisi target kamu ditandai dibayar" + link `/target`. Dipanggil dari `markCommissionPaid`.

Dikirim **setelah** commit, dibungkus guard `Notifier::send` (sudah ada). Kategori payload baru mis. `category='target'`, ikon `target`.

### 3.9 Hak akses

- Halaman/route admin: `role:manager|superadmin` (marketing → 403).
- `GET /target`: user terautentikasi melihat target miliknya sendiri (`user_id = auth id`). Tidak bisa lihat/ubah milik orang lain.
- Aksi tandai-dibayar & hapus: hanya admin (route gated).

---

## 4. Komponen yang Disentuh / Dibuat

- **Baru:** migrasi alter `tb_marketing_targets`; `resources/views/target/me.blade.php` (halaman marketing); method baru di `Notifier`.
- **Diubah:** `app/Models/MarketingTarget.php` (kolom/cast/relasi), `app/Services/MarketingTargetService.php` (rewrite ke rentang tanggal + method baru), `app/Http/Controllers/Pages/MarketingTargetController.php` (index/store/paid/destroy), `resources/views/marketing-target/index.blade.php` (daftar+form), `routes/web.php` (route admin baru + `/target`), `resources/views/layouts/sidebar.blade.php` (menu "Target Saya" marketing), `app/Services/MarketingDashboardService.php` (key `target` → currentForMarketing), `resources/views/dashboard/partials/marketing.blade.php` (periode+status), `app/Notifications/DatabaseNotification` payload kategori `target` (ikon di partial lonceng + index).

## 5. Rencana Test

- **Unit `MarketingTargetServiceTest` (rewrite/extend):**
  - `progressFor`: realisasi pakai rentang [start,end] (pembayaran di luar rentang tidak ikut); status aktif (today dalam rentang) vs berakhir (today>end); tertunggak (end+7 lewat & belum dibayar) true/false; komisi/capaian/sisa benar.
  - `currentForMarketing`: pilih target aktif; null bila tak ada; bila >1 aktif → end terdekat.
  - `createTarget` individu (n user) & `all` (1 baris per marketing aktif, batch_id sama); validasi non-marketing diabaikan.
  - `markCommissionPaid`: set flag+at+by.
- **Feature `MarketingTargetTest` (extend):**
  - admin GET index (lihat daftar) + POST store individu & semua (assert jumlah baris); marketing → 403.
  - POST paid menandai dibayar; DELETE menghapus (se-batch untuk "semua").
  - `GET /target` sebagai marketing → 200, lihat target sendiri, tidak lihat milik marketing lain.
  - dashboard marketing menampilkan periode + status target berjalan.
  - (hook) `createTarget` & `markCommissionPaid` mengirim `DatabaseNotification` ke marketing (Notification::fake).

Suite memakai DB test via `.env.testing`; migrasi ikut `RefreshDatabase`. **Dev/prod: jalankan `php artisan migrate`** untuk alter ini.

## 6. Asumsi & Risiko

- v1 belum di-merge & tabel kosong → alter aman tanpa migrasi data.
- Realisasi/komisi tetap turunan (rate × realisasi dalam rentang); perubahan rate/realisasi langsung tercermin sampai komisi ditandai dibayar (status dibayar tidak membekukan angka — bila perlu "snapshot" nilai komisi saat dibayar, itu perluasan terpisah).
- "Tertunggak" hanya flag visual; pengingat terjadwal ditunda (cron).
- `adminList` menghitung realisasi per target (rentang berbeda-beda sulit di-group); jumlah target ter-batas pada domain ini sehingga dapat diterima; bisa dioptimasi bila membengkak.
- Target "semua" tidak retroaktif untuk marketing baru.
