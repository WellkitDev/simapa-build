# Desain: Modul Slip Gaji Karyawan

- **Tanggal**: 2026-07-21
- **Status**: Disetujui (siap masuk perencanaan implementasi)
- **Bahasa UI**: Indonesia

## 1. Tujuan & Ringkasan

Menambahkan halaman baru **Slip Gaji Karyawan** dengan CRUD penuh, filter bulan/tahun,
pembuatan **PDF**, dan pengiriman **email** (PDF terlampir). Setiap slip memuat:

- **Rincian Penghasilan** — komponen penghasilan (Gaji Pokok, Tunjangan, dll.)
- **Rincian Potongan** — komponen potongan (BPJS, PPh21, pinjaman, dll.)
- **Bagian Akhir** — **Gaji Bersih / Take Home Pay** = total penghasilan − total potongan.

Modul dibangun **meniru pola modul Invoice/Refund** yang sudah ada: Controller di
`app/Http/Controllers/Pages/`, view PDF DomPDF, Mailable + Job antrean (queue) dengan
upload Google Drive, list DataTables, dan akses lewat `config/permissions.php`.

### Keputusan kunci (sudah disepakati)

1. **Standalone dari Keuangan** — modul **TIDAK** menyentuh Jurnal Kas / `CashEntry`.
   Semantik keuangan yang sensitif (pengeluaran masih 0, impor pengeluaran sengaja
   ditunda) tetap aman. Integrasi ke Jurnal Kas bisa ditambahkan belakangan.
2. **Baris komponen fleksibel per slip** — penghasilan & potongan diisi sebagai baris
   dinamis (tambah/hapus), plus preset default. **Tanpa tabel master gaji** per karyawan.
3. **Admin kelola + karyawan self-service** — `superadmin` & `accounting` membuat/kirim/
   hapus; setiap karyawan bisa melihat & mengunduh slip **terbit** miliknya sendiri.
4. **Karyawan = semua User aktif** (dipilih dari daftar user).
5. **Terbilang** (nominal rupiah dalam huruf) ditampilkan di PDF.
6. **Status sederhana**: `draft` → `terbit` (tanpa alur approval berlapis).

### Alternatif yang ditolak

- Menumpang tabel `tb_cash_entries` sebagai penyimpan slip → **ditolak**: menyentuh
  keuangan sensitif dan mencampur semantik kas dengan penggajian.
- Modul "dokumen" generik yang bisa dipakai ulang → **ditolak**: over-engineering (YAGNI).

## 2. Model Data

### Tabel `tb_salary_slips` (kepala slip)

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `slip_no` | string, unik | Auto: `SLP-YYYYMM-XXXX` (mis. `SLP-202607-0001`) |
| `user_id` | FK `users` | Karyawan penerima |
| `employee_name` | string | **Snapshot** nama saat dibuat |
| `employee_position` | string, nullable | **Snapshot** jabatan (dari `user_profiles.job_name`) |
| `period_year` | smallint | Tahun periode |
| `period_month` | tinyint (1–12) | Bulan periode |
| `status` | enum(`draft`,`terbit`) | default `draft` |
| `total_earnings` | decimal(15,2) | Snapshot hasil hitung |
| `total_deductions` | decimal(15,2) | Snapshot hasil hitung |
| `net_pay` | decimal(15,2) | Snapshot = earnings − deductions |
| `note` | text, nullable | Catatan pada slip |
| `sent_at` | timestamp, nullable | Diisi saat email terkirim |
| `created_by` / `updated_by` | FK `users`, nullable | |
| `timestamps`, `deleted_at` | | softDeletes |

**Constraint unik**: `(user_id, period_year, period_month)` di antara baris yang belum
terhapus → satu slip per karyawan per bulan. (Karena softDeletes, jika perlu unik parsial,
enforce juga di validasi controller: cek slip aktif untuk kombinasi tersebut.)

Kolom total di-*denormalisasi* (snapshot) agar DataTables index bisa menampilkan &
mengurutkan gaji bersih tanpa memuat semua baris (hindari N+1).

### Tabel `tb_salary_slip_lines` (baris komponen)

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `salary_slip_id` | FK cascade `tb_salary_slips` | |
| `type` | enum(`earning`,`deduction`) | Penghasilan / Potongan |
| `label` | string | Nama komponen (bebas) |
| `amount` | decimal(15,2) | Nominal (≥ 0) |
| `position` | int, default 0 | Urutan tampil |
| `timestamps` | | |

### Model

- `App\Models\SalarySlip`
  - `$table = 'tb_salary_slips'`, `HasFactory`, `SoftDeletes`, `$fillable` sesuai kolom.
  - `casts`: `period_year`/`period_month` int; total & net `decimal:2`; `sent_at` datetime.
  - Relasi: `lines()` hasMany (orderBy `position`), `earnings()` / `deductions()` (hasMany
    berfilter `type`), `employee()` belongsTo `User`, `creator()` belongsTo `User`.
  - `recalcTotals()` — hitung ulang `total_earnings`, `total_deductions`, `net_pay` dari
    baris lalu simpan. Dipanggil setelah baris disinkron di `store`/`update`.
  - Scope `scopeForPeriod($q, $year, $month)`.
- `App\Models\SalarySlipLine` — `$table = 'tb_salary_slip_lines'`, belongsTo `SalarySlip`.

## 3. Controller & Route

### `App\Http\Controllers\Pages\SalarySlipController` (admin: superadmin & accounting)

- `index(Request)` — resolve filter `year` (default tahun berjalan), `month` (angka atau
  `all`), `employee` (user_id atau `all`), `status`. Kembalikan view list + data karyawan &
  daftar tahun untuk dropdown.
- `create()` — form slip baru (daftar karyawan aktif, periode default = bulan berjalan).
- `store(Request)` — validasi; **`DB::transaction`**: buat `SalarySlip` (isi snapshot
  `employee_name`/`employee_position` dari user + profil, generate `slip_no`), simpan baris
  penghasilan & potongan, `recalcTotals()`. Guard **`@idempotent`** untuk cegah dobel submit.
- `show(int $id)` — detail slip + baris.
- `edit(int $id)` — hanya bila `status === 'draft'` (slip `terbit` dikunci; kalau perlu
  koreksi, hapus & buat ulang). Selain draft → redirect dengan pesan.
- `update(Request, int $id)` — seperti `store`, sinkron ulang baris + `recalcTotals()`.
- `destroy(int $id)` — softDelete.
- `pdf(int $id)` — `Pdf::loadView(...)->stream('SlipGaji_<slip_no>.pdf')`.
- `send(int $id)` — set `status = terbit`, `sent_at = now()`, `SendSalarySlipJob::dispatch`,
  kirim notifikasi in-app ke karyawan. Guard `@idempotent`.

### `App\Http\Controllers\Pages\EmployeeSalarySlipController` (self-service, semua user login)

- `me(Request)` — daftar slip **terbit** milik `Auth::id()` (filter bulan/tahun opsional).
- `pdf(int $id)` — unduh PDF, **wajib** cek `slip->user_id === Auth::id()` dan
  `status === 'terbit'`, selain itu `abort(403/404)`.

### Route (`routes/web.php`, dalam grup ter-autentikasi yang sudah ada)

```
salary/slip                 GET    salary.slip.index
salary/slip/create          GET    salary.slip.create
salary/slip                 POST   salary.slip.store
salary/slip/{id}            GET    salary.slip.show      (whereNumber)
salary/slip/{id}/edit       GET    salary.slip.edit
salary/slip/{id}            PUT    salary.slip.update
salary/slip/{id}            DELETE salary.slip.destroy
salary/slip/{id}/pdf        GET    salary.slip.pdf
salary/slip/{id}/send       POST   salary.slip.send

slip-gaji-saya              GET    salary.slip.me         (self-service)
slip-gaji-saya/{id}/pdf     GET    salary.slip.me.pdf     (self-service)
```

## 4. Desain PDF — `resources/views/salary/slips/salary_slip_pdf.blade.php`

Meniru gaya `payments/refunds/refund_pdf.blade.php` (font `DejaVu Sans`, tabel border tipis,
CSS inline). Susunan:

1. **Header** — "Avidpedia" + judul **SLIP GAJI KARYAWAN**; baris meta: Periode
   (`Juli 2026`) · No. Slip · Tanggal terbit.
2. **Data Karyawan** — tabel plain: Nama, Jabatan, Periode.
3. **Rincian Penghasilan** — tabel (Komponen | Nominal) untuk semua baris `earning`,
   diakhiri baris **Subtotal Penghasilan**.
4. **Rincian Potongan** — tabel (Komponen | Nominal) untuk semua baris `deduction`,
   diakhiri baris **Subtotal Potongan**.
5. **Bagian Akhir** — box menonjol: **GAJI BERSIH / TAKE HOME PAY** (angka besar) =
   penghasilan − potongan, dengan **terbilang** rupiah dalam huruf di bawahnya.
6. **Footer** — blok tanda tangan (Bagian Keuangan / Avidpedia) + catatan kerahasiaan
   ("Dokumen ini bersifat rahasia dan hanya untuk karyawan bersangkutan").

Format angka: helper `fn ($n) => 'Rp ' . number_format((float)$n, 0, ',', '.')` seperti
view lain.

**Terbilang**: tambah helper `App\Support\Terbilang::rupiah(int $n): string` (fungsi
rekursif standar bahasa Indonesia: satuan/belas/puluh/ratus/ribu/juta/miliar) menghasilkan
mis. "Satu juta dua ratus lima puluh ribu rupiah". Dipakai di view PDF.

Data view disiapkan oleh support class `App\Support\SalarySlipPdfData::for(SalarySlip $slip): array`
(pola sama `RefundPdfData`/`InvoicePdfData`) → mengembalikan slip, baris earning/deduction,
subtotal, net, terbilang, dan nama periode terformat.

## 5. Desain Email

- `App\Mail\SalarySlipMail` (Mailable, `SerializesModels`) — konstruktor
  `(SalarySlip $slip, array $data, ?string $pdf = null)`; `envelope` subjek
  **"Slip Gaji — Juli 2026"**; `content` view `pages.mails.salary_slip_mail`;
  `attachments` lampirkan PDF via `Attachment::fromData(...)->withMime('application/pdf')`
  dengan nama `SlipGaji_<slip_no>.pdf` (persis pola `RefundMail`).
- View `resources/views/pages/mails/salary_slip_mail.blade.php` — sapaan ke karyawan,
  periode, ringkasan (Total Penghasilan · Total Potongan · **Gaji Bersih**), keterangan
  "rincian lengkap ada di PDF terlampir". Gaya ringkas seperti `refund_mail.blade.php`.
- `App\Jobs\SendSalarySlipJob implements ShouldQueue` (pola `SendRefundJob`):
  1. Muat `SalarySlip` + relasi; kalau tidak ada / bukan `terbit`, keluar.
  2. Generate PDF (`Pdf::loadView(...)->output()`).
  3. Coba upload ke Google Drive `Application/SalarySlips/<YYYY>` (bungkus `try/catch`,
     kegagalan Drive hanya `Log::warning`, tidak menggagalkan email).
  4. Kalau `employee->email` ada → `Mail::to($email)->send(new SalarySlipMail(...))`.

## 6. UI/UX (profesional, DataTables)

Semua list memakai **DataTables** (`datatables.net-bs4`), gaya dari folder `template-web/`
(gitignored) seperti Arsip Judul.

- **Index (`salary/slips/index.blade.php`)** — bar filter (Tahun · Bulan dropdown ·
  Karyawan · Status) yang submit GET; tabel: No. Slip, Karyawan, Periode, Penghasilan,
  Potongan, **Gaji Bersih**, Status (badge), Aksi (Lihat · Edit [hanya draft] · PDF ·
  Kirim Email · Hapus). Tombol "Buat Slip".
- **Create/Edit (`salary/slips/form.blade.php`)** — pilih Karyawan + Periode (bulan/tahun);
  dua blok **baris dinamis** (Penghasilan & Potongan) dengan tombol tambah/hapus baris
  (JS vanilla/jQuery, tanpa build step); Subtotal per blok + **Gaji Bersih** terhitung live;
  input nominal pakai mask ribuan **`jquery.inputmask`** (sudah dipakai di modul keuangan);
  tombol **"Isi preset default"** menambah baris umum (Gaji Pokok, Tunjangan Jabatan,
  Tunjangan Transport | BPJS, PPh21). Form pakai `@idempotent`.
- **Show (`salary/slips/show.blade.php`)** — pratinjau slip + tombol PDF / Kirim Email /
  Edit (draft) / Hapus.
- **Slip Gaji Saya (`salary/slips/me.blade.php`)** — daftar ringkas slip terbit milik
  sendiri (Periode · Gaji Bersih · tombol Unduh PDF). DataTables sederhana.

## 7. Integrasi ke project ("terapkan semua pembaruan")

### Hak akses (WAJIB — kalau terlewat: 403 + test merah)

- Tambah modul ke `config/permissions.php`:
  ```php
  'salary' => [
      'label'   => 'Slip Gaji',
      'actions' => [
          'view'   => ['salary.slip.index', 'salary.slip.show'],
          'create' => ['salary.slip.create', 'salary.slip.store'],
          'edit'   => ['salary.slip.edit', 'salary.slip.update'],
          'delete' => ['salary.slip.destroy'],
          'send'   => ['salary.slip.send'],
          'export' => ['salary.slip.pdf'],
      ],
  ],
  ```
- Route self-service masuk daftar `'public'`: `salary.slip.me`, `salary.slip.me.pdf`
  (own-data, pola `report.daily`).
- `Database\Seeders\AccessMatrixSeeder`: beri `salary.*` ke role **accounting**
  (superadmin otomatis via `Gate::before`). Manager tidak diberi kecuali diminta.
- Jalankan `php artisan db:seed --class=AccessMatrixSeeder` di dev.

### Sidebar (`resources/views/layouts/sidebar.blade.php`)

- Menu **"Slip Gaji"** di seksi **Keuangan** (bungkus `@can('salary.view')`), pakai
  `nav_active('salary.slip.index')`.
- Menu **"Slip Gaji Saya"** di seksi personal (terbuka untuk semua user login), arah ke
  `salary.slip.me`.

### Notifikasi

- Tambah method di `App\Services\Notifier` (mis. `salarySlipIssued(SalarySlip $slip)`) yang
  mengirim notifikasi in-app ke `slip->user_id`: "Slip gaji <Periode> tersedia." Dipanggil
  dari `SalarySlipController::send`.

### Basis data dev

- Setelah membuat migration, jalankan `php artisan migrate` pada DB dev `avidpedi_simapa`
  (dan/atau app live) agar tidak 500 karena tabel belum ada. Uji tetap pakai
  `avidpedi_simapa_test` via `.env.testing`.

## 8. Pengujian (test DB `avidpedi_simapa_test`)

Feature test (`tests/Feature/SalarySlip/...`), role `accounting` via `Role::firstOrCreate`,
User dibuat langsung (tanpa factory Payment):

1. **Hitung total** — store dengan beberapa baris → `total_earnings`, `total_deductions`,
   `net_pay` benar.
2. **Unik per periode** — buat slip kedua untuk karyawan+periode sama → gagal validasi.
3. **Izin** — user `marketing` akses `salary.slip.index`/`store` → 403.
4. **Self-service kepemilikan** — karyawan A membuka `salary.slip.me.pdf` milik B → 403/404;
   membuka miliknya sendiri (terbit) → 200.
5. **Edit terkunci** — slip `terbit` di-`edit` → ditolak/redirect.
6. **Send** — `salary.slip.send` men-dispatch `SendSalarySlipJob` (`Bus::fake`/`Queue::fake`),
   set `status = terbit` & `sent_at`.
7. **PDF admin** — `salary.slip.pdf` untuk accounting → 200, `content-type` PDF.

## 9. Berkas yang dibuat/diubah (ringkasan)

**Baru**: 2 migration; `SalarySlip`, `SalarySlipLine`; `SalarySlipController`,
`EmployeeSalarySlipController`; `SalarySlipMail`; `SendSalarySlipJob`; `SalarySlipPdfData`,
`Terbilang` (Support); view `salary/slips/{index,form,show,me,salary_slip_pdf}.blade.php`,
`pages/mails/salary_slip_mail.blade.php`; database factory `SalarySlipFactory` (untuk test);
test Feature.

**Diubah**: `routes/web.php`, `config/permissions.php`, `AccessMatrixSeeder`,
`layouts/sidebar.blade.php`, `App\Services\Notifier`.

## 10. Di luar cakupan (YAGNI)

- Integrasi otomatis ke Jurnal Kas / `CashEntry` (bisa jadi fitur lanjutan).
- Tabel master komponen/gaji per karyawan.
- Perhitungan pajak/BPJS otomatis (nominal diisi manual).
- Alur approval berlapis, tanda tangan digital, atau ekspor batch multi-karyawan.
