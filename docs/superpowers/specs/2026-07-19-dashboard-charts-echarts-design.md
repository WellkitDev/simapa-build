# Dashboard: Donut per Judul + Visualisasi ECharts (superadmin/manager)

**Tanggal:** 2026-07-19
**Status:** Disetujui (brainstorm), menunggu plan implementasi
**Terkait:** `2026-07-17-dashboard-per-role-design.md`, memory `dashboard-per-role-followups`, `ui-conventions`, `keuangan-state-dan-lanjutan`

## Ringkasan

Dua kelompok perubahan pada dashboard:

1. **Perbaikan Donut** di 4 dashboard (superadmin, manager, admin, produksi): donut "per tahap"
   saat ini menghitung **satu baris per order-detail**, bukan **judul unik**. Ganti menjadi
   hitungan **judul unik per tahap** yang persis sama dengan menu **Pelacak Naskah**, dan
   **pisahkan menjadi dua donut: Buku vs Artikel**.
2. **Tambah Apache ECharts** (khusus dashboard superadmin/manager) untuk tiga visualisasi baru/di-restyle:
   perbandingan pemasukan-refund-order per marketing, tren traffic yang di-restyle, dan ketepatan
   produksi. Semua nilai uang tampil dalam format Rupiah (`Rp 240.000`, bukan `240000`).

Dashboard **marketing tidak disentuh** (sudah disetujui user; lihat memory).

## Keputusan yang sudah dikunci (dari brainstorm)

- **Donut**: dua donut per dashboard — **Buku** & **Artikel** — masing-masing menghitung **judul unik
  per tahap** (dedupe by `group_key`, tahap = *bottleneck* seperti Pelacak Naskah).
- **Chart pemasukan/refund/order per marketing**: **jangan campur skala**. Satu sumbu jujur per chart.
  Gunakan ECharts **Share Dataset**: satu dataset (`{name, pemasukan, refund, order}`) memberi makan
  dua chart — (a) batang pemasukan vs refund (Rp), (b) batang jumlah order (hitungan).
- **Chart ketepatan produksi**: batang **on-time %** per staf produksi + chart terpisah **jumlah selesai**
  (berbagi dataset). Tidak ada sumbu ganda.
- **Traffic**: tren pemasukan & order **di-restyle** dengan ECharts (bukan dual-axis; dua chart terpisah
  seperti sekarang), tetap ada toggle 7/30/90 hari.
- **Format uang**: Rupiah di semua sumbu/tooltip/label uang.
- **Library**: ECharts hanya untuk 3 item di superadmin/manager. Donut tetap ApexCharts (perubahan
  data-only). Marketing dashboard tidak berubah.
- **Periode chart perbandingan marketing**: **YTD tahun berjalan**.
- **Tema**: light-only (shell admin single-theme).

## Arsitektur

### A. Data layer

#### A1. `ManuscriptStageStatsService` (baru)

Service kecil terfokus yang mereproduksi penghitungan Pelacak Naskah untuk donut. Menjadi
**satu-satunya sumber** definisi "judul unik per tahap" agar donut dashboard dan papan Pelacak Naskah
tak pernah menyimpang.

Aturan penghitungan (identik dengan `ManuscriptTrackerController::buildGroupCards`):

- Ambil `OrderDetail` yang punya `titleProgress`, kelompokkan per `group_key` → satu **judul** per grup.
- **Tahap judul = bottleneck**: tahap paling awal (indeks terkecil pada urutan tahap tipe tsb.) di antara
  varian order-detail dalam grup. Judul buku pakai `TitleProgress::BOOK_STAGES`, artikel pakai
  `ARTICLE_STAGES`.
- **Tipe**: `bk_mandiri`/`bk_kolab` → **buku**; selain itu → **artikel**.
- **Kecualikan** judul yang tahap bottleneck-nya `menunggu_proses` atau final (`terbit`/`publish`),
  agar konsisten dengan donut marketing ("naskah aktif dalam pengerjaan").

Metode publik:

```
global(): array
  // {
  //   'buku'    => ['labels' => [stage...], 'series' => [count...]],
  //   'artikel' => ['labels' => [stage...], 'series' => [count...]],
  // }

forEditor(User $user): array
  // Bentuk sama; scope "mine" = judul yang punya minimal satu varian
  // assigned ke $user (bottleneck tetap dihitung atas seluruh grup),
  // meniru scope "mine" Pelacak Naskah.
```

Bentuk output `{labels, series}` cocok langsung dengan donut ApexCharts yang ada (`SimapaCharts.donut`).

Catatan efisiensi: penghitungan bottleneck butuh urutan tahap per tipe, jadi dilakukan di PHP setelah
`groupBy('group_key')` — sama seperti tracker. Bukan agregasi SQL murni. Untuk skala saat ini dapat
diterima; masuk backlog "SQL agregasi" bila volume besar (lihat memory `keuangan-state-dan-lanjutan`).

#### A2. `SalesDashboardService::perMarketingComparison(): Collection` (baru)

Satu baris per marketing untuk chart perbandingan (YTD tahun berjalan):

```
[
  ['name' => 'Andi', 'pemasukan' => 12_400_000, 'refund' => 240_000, 'order' => 8],
  ...
]
```

- `pemasukan` = `Payment::income()->forOrdersOf($marketing)->whereYear('paid_at', $year)->sum('amount')`
- `refund`    = `Payment::refund()->forOrdersOf($marketing)->whereYear('paid_at', $year)->sum('amount')`
- `order`     = `Order::where('user_id', $marketing->id)->whereYear('ordered_at', $year)->count()`
- Iterasi `User::role('marketing')`. Marketing tanpa aktivitas tetap muncul (nilai 0) agar
  perbandingan lengkap; urutkan `pemasukan` desc.

#### A3. Ketepatan produksi — pakai yang ada

`PerformanceService::allEditors()` sudah mengembalikan `on_time_rate`, `completed`, `active_queue`
per staf production/manager (30 hari). Tidak ada service baru; dibentuk ke dataset di controller/view.

### B. Wiring controller (`DashboardController`)

- `company()` (superadmin/manager) — tambah:
  - `'stageStats' => app(ManuscriptStageStatsService::class)->global()`
  - `'perMarketing' => app(SalesDashboardService::class)->perMarketingComparison()`
  - `'editors'` sudah ada (`PerformanceService::allEditors()`) → dipakai ulang untuk chart ketepatan.
- `admin()` — tambah `'stageStats' => ...->global()` untuk dua donut (kartu hitung tetap dari `$global`).
- `production()` — tambah `'stageStats' => ...->forEditor($user)` untuk dua donut "saya".

`$global['per_stage']` lama tak lagi dipakai donut, tapi field lain `$global` (kartu hitung) tetap
dipakai. Boleh dibiarkan atau dibersihkan saat implementasi bila jadi dead code.

### C. Frontend

#### C1. Donut (ApexCharts, semua 4 dashboard)

Ganti satu donut menjadi dua kolom bersebelahan:

- **"Judul Buku per Tahap"** ← `stageStats['buku']`
- **"Judul Artikel per Tahap"** ← `stageStats['artikel']`

Pakai helper `SimapaCharts.donut(data, totalLabel)` + `SimapaCharts.render()` yang sudah menangani
keadaan kosong ("Belum ada data"). Total di tengah = jumlah judul aktif tipe tsb.

Lokasi:
- `progress-global.blade.php` (`#globalStageChart` → jadi `#stageBukuChart` + `#stageArtikelChart`) —
  dipakai company (superadmin/manager).
- `admin.blade.php` (`#admStageChart` → dua donut).
- `production.blade.php` (`#prodStageChart` → dua donut).

#### C2. ECharts (baru) — hanya superadmin/manager (`company.blade.php`)

**Vendoring**: unduh `echarts.min.js` sekali ke `public/assets/plugins/echarts/echarts.min.js`
(pola self-host sama seperti `apexcharts`; tanpa CDN karena aplikasi jalan lokal/XAMPP). Muat via
`@push('plugin-scripts')`.

**Helper `public/assets/js/simapa-echarts.js`** — modul kecil `window.SimapaECharts`:
- `PALETTE` re-use dari `SimapaCharts.PALETTE` (konsistensi "hijau berarti sama").
- `rupiah(v)` → `'Rp ' + Number(v).toLocaleString('id-ID')`.
- Default animasi masuk (`animationDuration`, `animationEasing: 'cubicOut'`), grid resesif,
  ujung batang membulat (`itemStyle.borderRadius`), tooltip hover.
- Helper init aman-kosong (tampilkan "Belum ada data" bila dataset kosong), dan auto-`resize`
  pada `window resize`.
- Semua chart pakai komponen **`dataset`** dengan **object-array rows** + **`seriesLayoutBy`**
  sesuai permintaan ("Simple Example of Dataset", "Dataset in Object Array", "Series Layout By
  Column or Row", "Share Dataset").

**Tiga blok visualisasi** (di `company.blade.php`):

1. **Perbandingan per Marketing** — satu `dataset` (`source` = `perMarketing`), dua chart berbagi:
   - Chart A "Pemasukan vs Refund per Marketing": grouped bar, `encode` y = pemasukan & refund,
     sumbu-Y Rp, warna income=`success` / refund=`danger`, label & tooltip Rupiah.
   - Chart B "Jumlah Order per Marketing": bar, `encode` y = order, sumbu hitungan.
   - Legend + label ⇒ identitas tak pernah lewat-warna-saja (aman status green/red).

2. **Tren Traffic (restyle)** — dua chart area ECharts (Pemasukan, Order) menggantikan versi ApexCharts
   di company: smooth + gradient + animasi, tooltip crosshair, Rp pada pemasukan. Data dari
   `$mkt['income_trend']`/`$mkt['order_trend']` (90 hari) dengan slice sisi-klien; **pertahankan toggle
   7/30/90** (`#coRangeToggle`) — sekarang meng-update chart ECharts.

3. **Ketepatan Produksi** — satu `dataset` (`source` = editors → `{name, on_time_rate, completed}`),
   dua chart berbagi:
   - Chart A "Ketepatan (On-time %)": bar, sumbu 0–100%, diurutkan desc, `on_time_rate` null → 0
     (atau disaring; putuskan saat implementasi — default: tampilkan 0 dengan penanda "—" di tooltip).
   - Chart B "Jumlah Selesai (30 hari)": bar, sumbu hitungan.

Donut di company tetap ApexCharts (konsisten dengan admin/produksi); ECharts hanya untuk 3 blok di atas.
Tradeoff sengaja: satu halaman memuat dua lib chart — diterima demi blast-radius kecil & sesuai scope user.

## Format & desain (mengikuti skill dataviz)

- **Satu sumbu per chart** — tidak ada dual-axis (kesalahan keterbacaan #1). Ukuran beda-skala →
  chart terpisah yang berbagi dataset.
- **Rupiah** di semua tampilan uang (`Rp 240.000`).
- **Palet brand** dipakai ulang; income/refund = status green/red yang **selalu** disertai legend + label.
- **Mark**: batang tipis ujung membulat, garis 2px, grid resesif, tooltip hover, animasi masuk halus.
- **Legend** untuk chart ≥ 2 seri; chart 1 seri cukup judul.

## Testing

- **Unit** `ManuscriptStageStatsServiceTest`: judul multi-varian dihitung sekali; tahap = bottleneck;
  split buku/artikel benar; `menunggu_proses`/final dikecualikan; hasil `global()` cocok dengan
  jumlah distinct-`group_key` Pelacak Naskah untuk data uji yang sama; `forEditor()` men-scope "mine".
- **Unit** `SalesDashboardServiceTest` (tambah): `perMarketingComparison()` — income/refund/order per
  marketing benar, marketing tanpa aktivitas muncul dengan 0, refund tak mengurangi income.
- **Feature**: rute `/dashboard` tiap role tetap `assertOk` (server-render). Blade baru tak memecah render.
- **Manual (tak bisa headless)**: buka `/dashboard` sebagai superadmin/manager/admin/produksi di XAMPP;
  pastikan ECharts menggambar, donut Buku/Artikel benar, toggle traffic jalan, Rupiah tampil. Ini
  satu-satunya bagian yang tak terverifikasi otomatis (sama seperti follow-up dashboard sebelumnya).
- Jalankan suite terhadap DB test via `.env.testing` (memory `testing-setup`). Jangan sentuh DB nyata.

## Di luar cakupan (YAGNI)

- Migrasi donut/marketing ke ECharts (tetap ApexCharts).
- Dark mode chart.
- Agregasi SQL murni untuk stage stats (backlog bila volume besar).
- Filter periode dinamis untuk chart perbandingan marketing (tetap YTD).
- Migrasi `ProductionDashboardService::global()`/`completionTrend()` ke SQL GROUP BY (backlog terpisah).

## Risiko

- **Kecocokan hitungan Pelacak Naskah**: aturan bottleneck harus persis; ditutup uji unit yang
  membandingkan dengan penghitungan tracker.
- **Ukuran `echarts.min.js`** (~1 MB): self-host sekali; muat hanya di superadmin/manager.
- **Dua lib chart satu halaman**: terima; tidak ada konflik namespace (ApexCharts vs echarts global beda).
