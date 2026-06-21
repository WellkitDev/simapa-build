# Spec A — Marketing Dashboard Experience, Access Hardening & UI Cleanup

- **Tanggal:** 2026-06-21
- **Status:** Disetujui (siap masuk implementation plan)
- **Scope:** Pengalaman marketing — dashboard lebih informatif, tabel deadline, pengetatan hak akses, bersih-bersih UI.
- **Di luar scope (sengaja):** Sistem notifikasi (= Spec B terpisah), perubahan alur approve/reject tagihan, KPI konversi tagihan→order.

---

## 1. Latar Belakang

Dashboard marketing (`resources/views/dashboard/partials/marketing.blade.php`) sudah menampilkan ringkasan pemasukan, tren 30 hari, KPI progres naskah, dan donut "Naskah per Tahap" via `MarketingDashboardService::forUser()`. Permintaan: buat lebih menarik & informatif (statistik, traffic, donut), tambahkan tabel naskah mendekati deadline, dan pastikan marketing hanya bisa mengakses link marketing. Sebagian aksi sensitif (mis. `payment.approve`/`payment.reject`) saat ini **tidak dijaga middleware** sehingga bisa ditembak marketing walau tombolnya disembunyikan.

## 2. Tujuan & Kriteria Sukses

1. Dashboard marketing tampil lebih kaya: kartu statistik dengan indikator naik/turun, 2 KPI baru, grafik tren dengan toggle periode, donut yang lebih informatif.
2. Ada tabel DataTables di dashboard berisi naskah aktif mendekati/lewat deadline, ter-scope per marketing, dengan tab cepat (Lewat target / ≤7 hari / Bulan ini / Semua).
3. Marketing tidak bisa menjangkau route di luar menu marketing; aksi finansial admin (approve/reject pembayaran, mutasi status invoice) dikunci ke manager/superadmin.
4. Tombol & judul mati di halaman pembayaran dibereskan.
5. Semua perilaku baru tertutup test; suite tetap hijau.

---

## 3. Desain

### 3.1 Dashboard — polish + KPI

Pendekatan: **server-render + DataTables/ApexCharts sisi-klien** (tanpa endpoint AJAX baru). Semua data berasal dari `MarketingDashboardService::forUser()` yang diperluas.

**Kartu statistik (baris atas).** Kartu pemasukan (Hari Ini / Minggu Ini / Tahun Ini) dan Jumlah Order mendapat ikon dan **indikator delta** dibanding periode sebelumnya:

| Kartu | Periode kini | Pembanding |
|---|---|---|
| Pemasukan Hari Ini | hari ini | kemarin |
| Pemasukan Minggu Ini | minggu berjalan | minggu lalu |
| Pemasukan Tahun Ini | tahun berjalan | tahun lalu |
| Jumlah Order (tahun ini) | tahun berjalan | tahun lalu |

Delta = panah naik (hijau) / turun (merah) + persentase. Jika pembanding 0, tampilkan tanpa persentase (mis. "baru").

**Perbandingan setara (hindari apples-to-oranges):** delta membandingkan periode berjalan **sampai hari ini** dengan **rentang yang sama** pada periode sebelumnya — bukan periode penuh. Contoh: "Minggu Ini" = Senin s.d. hari ini dibanding Senin–(hari yang sama) minggu lalu; "Tahun Ini" = 1 Jan s.d. hari ini dibanding 1 Jan–(tanggal yang sama) tahun lalu.

**2 KPI baru:**
- **Total Piutang** — sisa tagihan order marketing yang belum lunas. Sumber: pakai ulang `FinancialReportService::piutang($user)['kpi']['sisa']` (definisi identik dengan Laporan Keuangan → konsisten). `MarketingDashboardService` meng-inject `FinancialReportService`.
- **Rata-rata Nilai Order** (tahun ini) — `Σ orderDetail.cost_amount ÷ jumlah order tahun ini`. Bila jumlah order 0 → tampilkan Rp 0.

**Traffic (grafik tren).** Seri `income_trend`, `order_trend`, `completion_trend` diperpanjang dari 30 → **90 hari**, dimuat sekali. Di atas grafik ada **toggle 7 / 30 / 90 hari** yang memotong seri terakhir di sisi klien (JS slice), tanpa request baru. Penyempurnaan visual: gradient fill, marker, dan tooltip mata-uang (Rp) untuk grafik pemasukan.

**Donut "Naskah per Tahap".** Tambah **total di tengah** (jumlah naskah aktif), persentase pada tooltip, dan palet warna tahap yang konsisten antar render.

### 3.2 Tabel Deadline (DataTables)

Section baru di dashboard marketing, di bawah kartu statistik.

**Definisi baris.** Satu baris = satu `TitleProgress` yang:
- statusnya **bukan** `TitleProgress::FINAL_STAGES` (`terbit`/`publish`), dan
- punya `target_date` (tidak null), dan
- naskahnya milik order marketing tsb (`titleProgress.orderDetail.order.user_id = $user->id`).

**Kolom:**

| Kolom | Sumber |
|---|---|
| Judul | `orderDetail.title` |
| Kode Order | `orderDetail.order.code_order` — link ke `route('order.indexJudul.progress', $titleProgress->id)` |
| Tahap | label dari `status` → `Str::title(str_replace('_',' ',status))`, tampil badge |
| Target | `target_date` (format `d M Y`) |
| Sisa hari | badge: **merah** `Lewat N hari` bila lewat; **kuning** `N hari lagi` bila ≤7; **netral** `N hari lagi` lainnya |
| Prioritas | `priority` (low/normal/high) badge |

**Pengurutan default:** paling mendesak dulu — `target_date` ascending (yang sudah lewat di paling atas). Disediakan kolom data numerik "sisa hari" (negatif = lewat) untuk sorting DataTables.

**Tab cepat** (filter sisi-klien, selaras definisi KPI yang sudah ada di service):

| Tab | Predikat (relatif `today`) | Flag baris |
|---|---|---|
| Lewat target | `target_date < today` | `data-overdue="1"` |
| ≤7 hari | `today ≤ target_date ≤ today+7` | `data-d7="1"` |
| Bulan ini | `today ≤ target_date ≤ endOfMonth` | `data-month="1"` |
| Semua (default) | semua baris | — |

Tiap baris membawa atribut `data-overdue/d7/month` (1/0) yang dihitung server-side; tab memfilter via custom search DataTables atas atribut tsb. Overlap antar-bucket diperbolehkan (mis. naskah jatuh tempo 5 hari = `d7` **dan** `month`).

**Data.** Method baru `MarketingDashboardService::deadlineRows(User $user): \Illuminate\Support\Collection` mengembalikan baris siap-tampil (judul, kode order, id progress, label tahap, target_date, sisa hari numerik, label sisa, prioritas, flag bucket). Blade me-render seluruh baris; DataTables menangani paging/sort/search.

### 3.3 Pengetatan Hak Akses (middleware role per-route)

Perubahan di `routes/web.php`:

| Route / grup | Middleware baru |
|---|---|
| `payment.approve`, `payment.reject` | `role:manager|superadmin` |
| Grup `payments` (prefix `payments`) | `role:marketing|manager|superadmin` |
| Grup `invoices` (prefix `invoices`) | `role:marketing|manager|superadmin` |
| `invoice.edit`, `invoice.update`, `invoice.updateStatus`, `invoice.cancel`, `invoice.refund` | `role:manager|superadmin` |
| Listing & buat/ubah order: `order.book.create/store/edit/update`, `order.journal.create/store`, `order.book.index` (Daftar Order), `order.book.indexJudul` (Arsip Judul) | `role:marketing|manager|superadmin` (per-route) |

**Sengaja TIDAK digate (tetap auth-only) — read-only & dipakai produksi:** `order.book.show`, `order.journal.show`, `order.indexJudul.detail`, `order.indexJudul.progress`. Papan **Manuscript** (view produksi) menaut ke `order.indexJudul.detail`, dan halaman detail judul menaut ke `order.book.show`; gating route ini akan mematahkan navigasi produksi (403). Route-nya read-only sehingga aman dibuka untuk semua user terautentikasi.

**Tidak diubah:** `tagihan.*` & `income.*` (sudah `role:marketing|manager|superadmin`), `user-management` (`can:access-usermanagement`), `manuscript.*` & `title.progress.*` (sudah dijaga). Aksi yang lebih ketat (approve/reject pembayaran, mutasi invoice) ditambahkan **di atas** gating grup, sehingga marketing boleh masuk grup tapi tetap ditolak pada aksi admin.

Catatan: `payment.update` sudah `role:manager|superadmin` — dipertahankan.

### 3.4 Bersih-bersih UI

Di `resources/views/payments/book/index.blade.php` (halaman menu "Disetujui", route `payment.index`):
- **Hapus** blok `@role(['marketing'])` berisi tombol mati **Trash / Export / Create** (`href="#"`).
- **Ganti** judul `Management Order Books` → **`Pembayaran Disetujui`**.

Dropdown aksi mati di dashboard *financial* (manager/superadmin) berada di luar scope spec ini.

---

## 4. Komponen yang Disentuh

- `app/Services/MarketingDashboardService.php` — tambah: delta periode-sebelumnya, KPI piutang (inject `FinancialReportService`), KPI rata-rata nilai order, perpanjang seri tren ke 90 hari, method `deadlineRows()`.
- `resources/views/dashboard/partials/marketing.blade.php` — kartu statistik + ikon + delta, KPI baru, toggle periode grafik, donut center-total, section + tabel deadline + tab + init DataTables.
- `routes/web.php` — middleware role per-route sesuai tabel 3.3.
- `resources/views/payments/book/index.blade.php` — hapus tombol mati, ganti judul.
- `DashboardController::index()` — tidak berubah strukturnya (tetap memanggil `MarketingDashboardService::forUser`), array `$mkt` bertambah key baru.

## 5. Rencana Test

**Unit — `tests/Unit/MarketingDashboardServiceTest.php`:**
- `deadlineRows` hanya memuat naskah aktif (non-final) ber-`target_date` milik order user; baris milik marketing lain tidak ikut.
- Flag bucket benar untuk kasus lewat target, jatuh tempo 5 hari (d7 & month), jatuh tempo akhir bulan (month saja), dan jatuh tempo bulan depan (tidak ber-flag).
- Delta KPI dihitung benar terhadap periode sebelumnya (termasuk pembanding 0).
- Total Piutang sama dengan `FinancialReportService::piutang($user)['kpi']['sisa']`.
- Rata-rata Nilai Order = Σ cost_amount ÷ jumlah order tahun ini (dan 0 saat tanpa order).
- Seri tren punya panjang 90.

**Feature — `tests/Feature/MarketingDashboardTest.php`:**
- Dashboard marketing me-render section tabel deadline + keempat tab.

**Feature akses (baru) — mis. `tests/Feature/MarketingAccessTest.php`:**
- Marketing → **403** pada `payment.approve`, `payment.reject`, `invoice.update`, `invoice.updateStatus`, `invoice.cancel`, `invoice.refund`.
- Production → **403** pada index payments/orders/invoices.
- Marketing tetap **200** pada route yang diizinkan (mis. `payment.index`, `order.book.index`, `invoice.index`).

Suite dijalankan terhadap `avidpedi_simapa_test` via `.env.testing` (jangan DB asli).

## 6. Asumsi & Risiko

- Data per-marketing kecil (puluhan naskah aktif), jadi server-render + DataTables sisi-klien memadai; tidak perlu endpoint AJAX/server-side.
- `orderDetail.cost_amount` adalah nilai order kanonik (sesuai pemakaian di `FinancialReportService`).
- Menambah middleware role pada grup payments/invoices/order tidak memutus alur manager/superadmin (mereka termasuk dalam set izin) maupun dashboard produksi (tidak memanggil route tsb).
