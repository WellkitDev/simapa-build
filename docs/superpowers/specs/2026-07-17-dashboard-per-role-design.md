# Dashboard per Role — Desain

**Tanggal:** 2026-07-17
**Status:** Disetujui, siap ditulis rencananya

## Latar

`DashboardController::index()` bercabang lewat rentetan `if` yang saling tumpang tindih: `isProductionOnly` → partial `production`, `isMarketingOnly` → partial `marketing`, lalu `else` menampung sisanya ke partial `financial`.

Cabang `else` itu bukan keputusan desain, melainkan sisa. Akibatnya:

- **admin** dan **accounting** mendarat di partial `financial` tanpa pernah dirancang ke sana. Admin — yang menurut route hanya mengurus dokumen/data — melihat **Total Pemasukan seluruh perusahaan**. Ini kebocoran angka keuangan ke role yang tidak berkepentingan.
- **accounting** punya dashboard sungguhan di halaman terpisah (`accounting/dashboard`, `CashRecapService` + `ExpenseGapService`) yang tidak tersambung ke `/dashboard` — dua dashboard bersaing.
- Partial `financial` tertinggal jauh dari `marketing`: hanya total sepanjang masa + sparkline, tanpa delta, piutang, target, atau toggle rentang. Di dalamnya masih ada filter `$isMarketing` yang jadi kode mati untuk role yang benar-benar memakainya.
- Superadmin/manager tidak punya pandangan perusahaan: ada produksi global + performa editor, tapi tidak ada performa tim marketing, piutang total, maupun kas/laba.

Dashboard **marketing** sudah matang dan menjadi acuan bentuk untuk pekerjaan ini.

## Batasan yang tidak boleh dilanggar

1. **Definisi pemasukan tetap `Payment::income()`** (paid, bukan refund). Ini keputusan semantik yang sudah mahal didapat. Tidak boleh ada salinan kedua yang bisa melenceng.
2. **Manager tidak boleh melihat kas/laba.** Route akuntansi dijaga `role:superadmin|accounting`; dashboard tidak boleh membocorkan apa yang route-nya tutup.
3. **Admin tidak boleh melihat angka uang.**
4. Gaya UI mengikuti `template-web/` (gitignored, jangan di-commit); semua tabel memakai DataTables sesuai konvensi proyek.
5. Test berjalan di DB `avidpedi_simapa_test` lewat `.env.testing`, tidak pernah menyentuh DB asli.

## Keputusan

| Topik | Keputusan |
|---|---|
| Cakupan superadmin/manager | Agregat perusahaan + dropdown filter per marketing |
| Admin | Papan kerja dokumen + ringkasan produksi, tanpa angka uang |
| Accounting | Dashboard kas jadi dashboard utamanya |
| Superadmin vs manager | Basis sama; superadmin dapat blok kas tambahan |
| Produksi | Disamakan dengan marketing (tabel deadline, delta, toggle, Total Selesai) |
| Marketing | Tampilan tidak berubah |
| Arsitektur | Scope jadi parameter + agregasi dipindah ke SQL |

## Arsitektur

### Resolusi role

Ganti rentetan `if` dengan peta eksplisit berurutan prioritas. Urutan menyelesaikan user multi-role tanpa `hasAnyRole` bersarang: superadmin yang juga marketing mendapat dashboard superadmin.

| Urutan | Role | Partial | Sumber data |
|---|---|---|---|
| 1 | superadmin | `company` + blok kas | `SalesDashboardService::forCompany` · `ProductionDashboardService::global` · `PerformanceService::allEditors` · `CashRecapService` · `ExpenseGapService` |
| 2 | manager | `company` | sama, **tanpa** `CashRecapService` dan `ExpenseGapService` |
| 3 | accounting | `accounting` | `CashRecapService` · `ExpenseGapService` |
| 4 | admin | `admin` | `AdminDashboardService` · `ProductionDashboardService::global` |
| 5 | marketing | `sales` | `SalesDashboardService::forUser` |
| 6 | production | `production` | `ProductionDashboardService::forUser` · `PerformanceService::forEditor` |
| — | lainnya | pesan netral | — |

### Layanan

**`MarketingDashboardService` → `SalesDashboardService`.** Nama lama menyesatkan begitu superadmin memakainya.

Satu `build(Closure $scope): array` privat memegang seluruh perhitungan:

- `forUser(User $u)` → scope `fn ($q) => $q->where('user_id', $u->id)`
- `forCompany(?User $filter = null)` → scope kosong, atau scope satu marketing bila `$filter` diisi

Kartu, delta, tren, piutang, dan target semuanya lewat jalur kode yang sama. Angka superadmin dan angka marketing **tidak bisa** berbeda karena bukan sekadar mirip — memang kode yang sama.

**`AdminDashboardService`** — baru.

**Partial `accounting`** — diekstrak dari view `accounting/dashboard`. Halaman `/accounting/dashboard` tetap hidup dan memakai partial yang sama, jadi tidak ada dua versi yang bisa berbeda.

**Partial `financial` dihapus**, bukan ditinggal. Setelah keenam role punya rumah, tak ada yang memakainya; meninggalkannya berarti meninggalkan kartu Total Pemasukan tanpa penjaga role di dalam repo.

## Isi tiap dashboard

### Superadmin & manager — `company`

Dropdown filter di kepala halaman: "Semua marketing" (bawaan) atau satu nama. Memilih satu nama menampilkan persis apa yang marketing itu lihat di dashboard-nya sendiri.

Blok berurutan:

1. **Ringkasan Pemasukan** — hari/minggu/tahun + delta
2. **Target Tim** — satu-satunya blok yang benar-benar baru. Saat filter "Semua": tabel per marketing (nama, periode, target, realisasi, capaian %, komisi, status) — jawaban atas "siapa mencapai target". Saat difilter satu orang: progress bar tunggal seperti punya marketing.
3. **Statistik Order & Tagihan** — jumlah order bulan/tahun, total piutang, rata-rata nilai order
4. **Traffic** — toggle 7/30/90
5. **Produksi Global** — kartu + donut per tahap + tren penyelesaian (`ProductionDashboardService::global()`, sudah ada)
6. **Performa Editor** — `PerformanceService::allEditors()`, sudah ada
7. **Naskah Mendekati Deadline** — seluruh perusahaan

**Blok kas (superadmin saja):** saldo total, laba bulan berjalan, pemasukan/pengeluaran YTD, peringatan celah pengeluaran, tautan ke Jurnal Kas. Dijaga `@role('superadmin')` di view **dan** datanya tidak diambil di controller bila bukan superadmin — disembunyikan CSS saja tidak cukup.

### Accounting — `accounting`

Rekap kas 12 bulan, YTD, peringatan celah pengeluaran, tautan ekspor CSV/PDF.

### Admin — `admin`

Tanpa satu pun angka uang. Kartu pekerjaan — semuanya hal yang admin memang berwenang mengerjakannya menurut route: judul dengan dokumen belum lengkap · arsip menunggu artefak · submission jurnal aktif · ISBN tersedia/terpakai · pengumuman aktif. Ditambah ringkasan produksi global (donut per tahap, lewat target, jatuh tempo ≤7 hari) agar admin tahu apa yang akan masuk mejanya.

Sengaja **tidak** menampilkan "judul menunggu approve": approve dijaga `role:superadmin|manager`, jadi angka itu hanya akan jadi hitungan yang admin tak bisa apa-apakan.

### Produksi — `production`

Yang ada dipertahankan, ditambah:

- Kartu **Total Selesai**
- Indikator delta pada kartu (pakai `dashboard.partials.delta` yang sudah ada)
- Toggle 7/30/90 pada chart aktivitas
- **Tabel Naskah Mendekati Deadline** (DataTables + tab Semua/Lewat target/≤7 hari/Bulan ini), di-scope ke `assigned_user_id`

### Marketing — `sales`

Tampilan tidak berubah sama sekali. Hanya nama partial dan nama service yang bergeser.

## Standar visual

**Satu sumber warna dan komponen.** Tiap partial sekarang menuliskan hex-nya sendiri (`#6571ff`, `#05a34a`, `#fbbc06`, `#ff3366` diulang di marketing, financial, production). Dengan enam dashboard itu dijamin melenceng. Semua opsi ApexCharts (area, donut, sparkline) dan paletnya pindah ke satu berkas JS bersama, sehingga "hijau" berarti hal yang sama di mana pun.

**Delta sadar arah.** `dashboard.partials.delta` sekarang selalu hijau untuk `up`. Benar untuk pemasukan; **salah untuk piutang**, yang naiknya buruk dan kini tampil di dashboard perusahaan. Tanpa perbaikan, piutang membengkak akan tampil hijau berpanah naik — grafik yang aktif menyesatkan. Partial menerima flag `invertGood`; naik-itu-buruk tampil merah.

**Persentase jujur.**

- Pembanding nol → `delta()` mengembalikan `pct => null`, tampil "baru". Perilaku sekarang sudah benar; dipertahankan.
- Pembanding sangat kecil → Rp 50rb ke Rp 5jt tampil "+9900%": benar tapi tak bermakna. Di atas ±999% ditampilkan ">999%" dengan tooltip nilai absolut.
- `on_time_rate` `null` (tak ada naskah bertarget) tampil "—", bukan "0%". Nol berarti semua telat — tuduhan yang salah ke editor.

**Keadaan kosong.** Donut berdata nol sekarang merender kotak putih. Tiap chart dan tabel dapat placeholder "Belum ada data" yang eksplisit.

**Catatan pelaksanaan:** saat blok chart betul-betul ditulis, muat skill `dataviz` lebih dulu agar bentuk dan warnanya konsisten.

## Aliran data

Controller menentukan role → memanggil service → mengoper array ke partial. **Tidak ada query di view.**

Filter marketing lewat query string `?marketing=<id>`, divalidasi ke daftar user ber-role marketing. Id asing jatuh ke "Semua", bukan error.

**Agregasi pindah ke SQL.** `dailySum`, `avgOrderValue`, dan `deadlineRows` sekarang menarik baris ke PHP lalu mengelompokkan (`->get()` lalu `->groupBy()`). Aman selama ter-scope satu marketing; begitu dilepas ke seluruh perusahaan, itu memuat **semua** payment 90 hari dan **semua** order setahun ke memori. Perbaikan:

- `dailySum` / `dailyCount` → `GROUP BY DATE(...)`
- `avgOrderValue` → `AVG` lewat join
- `deadlineRows` → `limit` + eager-load

Sejalan dengan backlog hardening "saldo snapshot/SQL agregasi" yang sudah tercatat.

## Penanganan galat

- Service mengembalikan bentuk array yang sama walau kosong; view tak pernah kena undefined index.
- Blok kas superadmin dibungkus try/catch, gagal jadi kartu "Data kas tidak tersedia". Kegagalan di akuntansi tidak boleh menjatuhkan seluruh dashboard.

## Pengujian

TDD. DB `avidpedi_simapa_test` lewat `.env.testing`.

**Feature:**

- Tiap role menerima partial yang benar (6 kasus + fallback).
- **Manager tidak menerima blok kas**; **admin tidak menerima angka pemasukan**. Ini uji kebocoran, bukan uji tampilan — assert pada data/response, bukan pada visibilitas CSS.
- `?marketing=<id>` untuk superadmin menghasilkan angka **identik** dengan `forUser` marketing itu. Inilah yang mengunci janji "kode yang sama".
- Id asing pada `?marketing=` jatuh ke "Semua".

**Unit:**

- `forCompany` = jumlah seluruh marketing.
- `delta()` pada pembanding nol dan pembanding kecil (>999%).
- `invertGood` membalik warna.

## Di luar cakupan

- **Struk gaji / payroll** — subsistem baru dari nol, jadi spec terpisah setelah ini. Tidak ada model Employee/Payroll/SalaryComponent sama sekali saat ini; `CashDistribution` hanya alokasi profit tingkat tim dan `MarketingTarget` hanya komisi. Juga butuh keputusan data kepegawaian, gateway WhatsApp (belum ada — semua WA di aplikasi ini hanya tautan `wa.me` manual), dan integrasi ke kas.
- Perubahan batas role di route akuntansi.
- Refactor di luar berkas dashboard.
