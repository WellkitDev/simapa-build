# Spec: Tagihan + Invoice Marketing (redesign alur proforma)

**Tanggal:** 2026-06-17
**Status:** Disetujui — siap masuk rencana implementasi
**Area:** Pembayaran (Invoice, Tagihan), Order intake, Sidebar (role-aware)
**Branch:** `Fitur` (jangan merge dulu)

---

## Ringkasan

Membereskan alur invoice marketing yang membingungkan. Saat ini "Buat Invoice proforma/regular" **mewajibkan order sudah ada** — sehingga invoice proforma tak bisa berfungsi sebagai tagihan ke calon klien sebelum order/bayar. Plus halaman daftar invoice error DataTables saat kosong.

Solusi tiga bagian:

1. **Fix Invoice index** — hilangkan error *DataTables: Incorrect column count*, aktifkan DataTables penuh (cari/sort/paginate/responsive), tombol Download PDF (gated). Hapus form manual "Buat Invoice" — invoice jadi **murni sistem** (auto saat payment order di-approve, alur existing).
2. **Tagihan** — entitas baru menggantikan konsep "proforma": marketing input manual (data lengkap mirip order) → **approve superadmin** → download PDF tagihan → (klien bayar di luar sistem) → **Buat Order dari Tagihan** (form order normal, prefill ringan) lewat alur order existing.
3. **Sidebar restructure** role-aware.

**Tanpa auto-create order.** Order tetap dibuat lewat alur normal yang sudah ada (hanya di-prefill ringan dari tagihan). Ini sengaja agar tak menduplikasi logika order/DP/pelunasan.

---

## 1. Konsep & Keputusan Desain

- **Tagihan = dokumen tagih mandiri**, berdiri sendiri di `tb_tagihan` (BUKAN menumpang `tb_invoices`). Alasan: tagihan tak punya order; menumpang invoice memaksa `order_id` nullable + banyak kolom tagihan-only → invoice gemuk & ambigu. Di UI pun "tagihan" & "invoice" item terpisah. *(Alternatif menumpang invoice ditolak.)*
- **Invoice `type=proforma` dipensiunkan.** Form "Buat Invoice" manual dihapus; invoice hanya dibuat sistem (`PaymentBookController` saat approve payment — alur existing, tak diubah). Inilah yang menghapus kebingungan: marketing tak pernah lagi bikin invoice manual.
- **Tidak ada auto-create order.** Setelah tagihan dibayar, marketing menekan **"Buat Order dari Tagihan"** → form order normal terbuka dengan **prefill ringan** (field skalar 1:1). Order dibuat lewat `OrderBookController@store` / jurnal yang sudah ada.
- **Prefill ringan:** judul, tipe (buku/jurnal), nama/email/telp klien, catatan → terisi otomatis lewat `old()` default. **Author & scope jurnal tetap diisi manual** marketing di form order (menghindari prefill struktur dinamis yang mahal). Hemat ~70% ketik ulang.
- **Tidak ada dobel-hitung:** tagihan yang `disetujui` tapi belum jadi order **tidak pernah** masuk angka pemasukan di Laporan/Dashboard. Uang baru terhitung saat sudah jadi **Order + Payment paid** lewat alur normal (yang otomatis ter-cover oleh `MarketingDashboardService`/Income existing). **Tidak ada perubahan** di service pemasukan.
- **Scoping:** marketing hanya melihat tagihan miliknya (`created_by = Auth::id()`); manager/superadmin melihat semua.

---

## 2. Lifecycle Tagihan

```
diajukan ──approve(superadmin)──► disetujui ──buatOrder(marketing)──► jadi_order
   │                                  │
   └──reject(superadmin)──► ditolak    └──cancel──► dibatalkan
        │
        └──(marketing edit & ajukan ulang)──► diajukan
```

| Status | Arti | Siapa yang set |
|--------|------|----------------|
| `diajukan` | Dibuat marketing, menunggu approve (TIDAK ada `draft` — langsung diajukan) | Marketing (create) |
| `disetujui` | Di-approve, boleh di-download & dikirim ke klien | Superadmin |
| `ditolak` | Ditolak (+catatan); marketing bisa edit & ajukan ulang | Superadmin |
| `jadi_order` | Sudah dikonversi jadi Order (loose link `order_code`) | Marketing (buatOrder) |
| `dibatalkan` | Disetujui tapi batal/klien tak bayar | Marketing (pemilik) / Superadmin |

Edit tagihan hanya boleh saat `diajukan` atau `ditolak`. Setelah `disetujui`/`jadi_order` tagihan terkunci (kecuali `cancel`).

---

## 3. Role Permission

| Aksi | Marketing | Manager | Superadmin |
|------|:---------:|:-------:|:----------:|
| Buat tagihan | ✓ | ✓ | ✓ |
| Edit tagihan (saat diajukan/ditolak) | ✓ (milik) | ✓ | ✓ |
| Approve / Reject tagihan | ✗ | ✗ | ✓ |
| Download PDF tagihan (saat disetujui/jadi_order) | ✓ (milik) | ✓ | ✓ |
| Buat Order dari Tagihan | ✓ (milik, saat disetujui) | ✓ | ✓ |
| Batalkan tagihan | ✓ (milik) | ✓ | ✓ |
| Lihat semua tagihan | ✗ (hanya milik) | ✓ | ✓ |
| Download Invoice PDF (saat diterbitkan/lunas) | ✓ (milik) | ✓ | ✓ |

---

## 4. Database Schema

### Tabel baru `tb_tagihan`

| Kolom | Tipe | Keterangan |
|-------|------|-----------|
| id | bigint PK | |
| tagihan_no | varchar unik | `TAG-YYYYMM-NNNN` (generate dengan lockForUpdate, pola seperti `code_order`) |
| created_by | bigint FK → users | Marketing pembuat |
| client_name | varchar | Nama klien/author |
| client_email | varchar nullable | |
| client_phone | varchar nullable | |
| client_institution | varchar nullable | |
| title | varchar | Judul naskah |
| type | enum `buku`/`jurnal` | Untuk prefill tipe order |
| author_names | text nullable | Nama author (teks bebas — untuk isi bill & rujukan) |
| description | text nullable | Rincian item/jasa |
| amount | bigint | Nominal tagihan (Rp) |
| due_at | date nullable | Jatuh tempo bayar |
| note | text nullable | Catatan |
| status | varchar | `diajukan`/`disetujui`/`ditolak`/`jadi_order`/`dibatalkan` |
| approved_by | bigint FK nullable → users | |
| approved_at | timestamp nullable | |
| reject_note | text nullable | Alasan tolak |
| order_id | bigint FK nullable → tb_orders | Loose link saat jadi order |
| order_code | varchar nullable | Salinan kode order (tampilan cepat) |
| timestamps | | |

### Tabel baru `tb_tagihan_logs` (audit, pola `tb_invoice_logs`)

| Kolom | Tipe |
|-------|------|
| id | bigint PK |
| tagihan_id | bigint FK → tb_tagihan |
| from_status | varchar(50) |
| to_status | varchar(50) |
| changed_by | bigint FK → users |
| note | text nullable |
| created_at | timestamp |

Tidak ada perubahan destruktif pada tabel existing. `tb_invoices` tetap; hanya alur pembuatan manualnya yang dihentikan.

---

## 5. Komponen / File

| Aksi | Path | Tanggung jawab |
|------|------|----------------|
| Create | `database/migrations/..._create_tb_tagihan_table.php` | Tabel tagihan |
| Create | `database/migrations/..._create_tb_tagihan_logs_table.php` | Audit log |
| Create | `app/Models/Tagihan.php` | Model + relasi + scope + const STATUSES + helper |
| Create | `app/Models/TagihanLog.php` | Model log |
| Create | `database/factories/TagihanFactory.php` | Factory untuk test |
| Create | `app/Http/Controllers/Pages/TagihanController.php` | CRUD + approve/reject/cancel/pdf/buatOrder |
| Create | `app/Support/TagihanPdfData.php` | Siapkan data PDF (pola `InvoicePdfData`) |
| Create | `resources/views/payments/tagihan/index.blade.php` | Daftar (DataTables) |
| Create | `resources/views/payments/tagihan/create.blade.php` | Form buat/edit |
| Create | `resources/views/payments/tagihan/show.blade.php` | Detail + log + aksi role-aware |
| Create | `resources/views/payments/tagihan/tagihan_pdf.blade.php` | Template PDF (adaptasi `book_invoice_pdf`) |
| Modify | `routes/web.php` | Routes tagihan + hapus route create/store invoice manual |
| Modify | `resources/views/payments/invoices/index.blade.php` | Fix bug DataTables + init penuh + tombol Download gated |
| Delete/retire | `resources/views/payments/invoices/create.blade.php` | Form manual invoice dihapus |
| Modify | `app/Http/Controllers/Pages/InvoiceController.php` | Hapus `create()`/`store()`; PDF/index tetap |
| Modify | `app/Http/Controllers/Pages/OrderBookController.php` (+ jurnal) | `create()` baca `?from_tagihan=` → prefill; `store()` tandai tagihan `jadi_order` |
| Modify | `resources/views/orders/book/create.blade.php` (+ jurnal create) | Field skalar pakai `old('x', $prefill['x'] ?? '')` |
| Modify | `resources/views/layouts/sidebar.blade.php` | Restructure role-aware + rename "DP/Tagihan"→"DP/Pembayaran" |
| Create | `tests/Feature/TagihanLifecycleTest.php` | Lifecycle, approval, scoping, prefill, no-double-count |
| Create | `tests/Feature/InvoiceIndexTest.php` | Empty state tanpa error, download gating |

---

## 6. Controller `TagihanController`

| Method | HTTP | Route name | Role |
|--------|------|-----------|------|
| `index()` | GET | `tagihan.index` | semua (marketing scoped) |
| `create()` | GET | `tagihan.create` | semua |
| `store()` | POST | `tagihan.store` | semua (status awal `diajukan`) |
| `show($id)` | GET | `tagihan.show` | semua (scoped) |
| `edit($id)` | GET | `tagihan.edit` | pemilik/manager/superadmin, hanya saat diajukan/ditolak |
| `update($id)` | PUT | `tagihan.update` | idem |
| `approve($id)` | POST | `tagihan.approve` | superadmin |
| `reject($id)` | POST | `tagihan.reject` | superadmin (+note) |
| `cancel($id)` | POST | `tagihan.cancel` | pemilik/superadmin |
| `pdf($id)` | GET | `tagihan.pdf` | scoped, hanya bila `disetujui`/`jadi_order` |
| `buatOrder($id)` | GET | `tagihan.buatOrder` | pemilik, saat `disetujui` → redirect ke order create `?from_tagihan=$id` |

Setiap transisi status menulis `TagihanLog` (pola `InvoiceLog`), dalam `DB::transaction`.

---

## 7. Alur "Buat Order dari Tagihan" (prefill ringan + link)

```
Tagihan disetujui → marketing klik "Buat Order dari Tagihan"
  → GET tagihan.buatOrder($id): validasi pemilik + status disetujui
  → redirect ke order.book.create / order.journal.create dengan ?from_tagihan=$id
  → OrderBookController@create: jika from_tagihan ada & valid milik user,
      muat Tagihan, susun array $prefill (title, type, client_name/email/phone, note)
      → view create membaca old('field', $prefill['field'] ?? '')
  → marketing lengkapi author/scope, submit
  → OrderBookController@store: jika request punya from_tagihan,
      setelah order tersimpan → Tagihan.update(status=jadi_order, order_id, order_code) + log
```

Edge: jika tagihan sudah `jadi_order`/`dibatalkan`, tombol "Buat Order" disembunyikan dan `buatOrder` menolak (redirect back + pesan).

---

## 8. Fix Invoice Index (Part 1)

- **Bug:** baris `@empty` `<td colspan="7">` (1 sel vs 7 header) memicu *Incorrect column count* saat 0 invoice. **Fix:** hilangkan baris `@empty` manual; biarkan DataTables menampilkan empty-state bawaan (`language.emptyTable`).
- **DataTables penuh:** init dengan `searching/ordering/paging/responsive` aktif (pola sama Arsip Judul / `datatables.net-bs4`). Kolom Aksi non-orderable.
- **Download PDF:** tombol Download (route `invoice.pdf` yang sudah ada, sudah scoped marketing) tampil bila `status ∈ {diterbitkan, lunas}` ("download bila sudah approve").
- **Hapus tombol/route "Buat Invoice":** invoice murni sistem.

---

## 9. Sidebar (Part 3, role-aware)

```
Dashboard
PEMBAYARAN
  ├─ Tagihan                 (tagihan.index)            [marketing/manager/superadmin]
  ├─ Invoice                 (invoice.index)            [marketing/manager/superadmin]
  └─ Pembayaran ▸            (collapse)
       ├─ DP/Pembayaran      (payment.dp.index)   ← rename dari "DP/Tagihan"
       ├─ Pelunasan          (payment.fp.index)
       └─ Disetujui          (payment.index)
ORDER & NASKAH               [marketing/manager/superadmin]
  ├─ Buat Order ▸            (Buku / Jurnal)
  ├─ Daftar Order
  └─ Arsip Judul
PRODUKSI ▸ Manuscript Tracker [production/manager/superadmin]
LAPORAN ▸ Pendapatan
AKUN
  ├─ Manajemen User          [manager/superadmin]
  └─ Profil
```

"Buat Order" tetap di grup Order/Naskah (rumah aslinya). Grup Pembayaran taruh di atas (alur jual marketing mulai dari menagih). Bridging tagihan→order lewat tombol, bukan navigasi menu.

---

## 10. UI Tagihan

**Index** (DataTables penuh): No Tagihan · Klien · Judul · Tipe · Nominal · Status (badge) · Jatuh Tempo · Aksi (Detail / Download bila disetujui / Buat Order bila disetujui). Filter status & tipe.

Badge status: `diajukan`=secondary, `disetujui`=success, `ditolak`=danger, `jadi_order`=primary, `dibatalkan`=dark.

**Create/Edit:** form field — Klien (nama/email/telp/institusi), Judul, Tipe (radio buku/jurnal), Author (teks), Deskripsi, Nominal, Jatuh Tempo, Catatan.

**Show:** info lengkap + log history (from→to, oleh, kapan, catatan); aksi kondisional: Superadmin (Approve/Reject), Pemilik (Edit saat diajukan/ditolak, Download saat disetujui, Buat Order saat disetujui, Batalkan).

---

## 11. Error Handling / Edge Cases

| Kondisi | Penanganan |
|---------|-----------|
| Marketing approve/reject tagihan | 403 |
| Edit tagihan saat sudah disetujui/jadi_order | 403 / tombol disembunyikan |
| Download PDF tagihan sebelum disetujui (masih diajukan/ditolak) | 403 / tombol disembunyikan |
| `buatOrder` saat bukan disetujui atau bukan pemilik | redirect back + pesan |
| Tagihan sudah jadi_order, klik Buat Order lagi | ditolak (idempoten) |
| Invoice index kosong | empty-state DataTables, tanpa error column count |
| Marketing lihat tagihan milik orang lain | tak tampil (scoped) / 404 di show |
| Nomor tagihan balapan (race) | `lockForUpdate` saat generate |

---

## 12. Kualitas (QA/QC)

**Feature — `TagihanLifecycleTest`:**
- create → status `diajukan` + log awal; scoped (marketing lain tak lihat).
- superadmin approve → `disetujui` + log; marketing tak bisa approve (403).
- reject → `ditolak` + reject_note; marketing edit & ajukan ulang.
- download PDF hanya saat `disetujui` (403/redirect bila belum).
- `buatOrder` saat `disetujui` → redirect ke order create dengan `from_tagihan`; create memuat prefill; store menandai tagihan `jadi_order` + order_code; klik ulang ditolak.
- cancel → `dibatalkan`.
- **No double-count:** tagihan `disetujui` belum jadi order → `MarketingDashboardService::forUser` pemasukan tetap 0 (tak ada Payment).

**Feature — `InvoiceIndexTest`:**
- index 0 invoice → 200, tanpa markup baris colspan manual (empty handled DataTables).
- tombol Download muncul untuk invoice `diterbitkan`/`lunas`, tidak untuk `draft`.
- route create/store invoice manual dihapus (404/route absen).

Target: seluruh suite tetap hijau (saat ini 137 passed) + test baru. Jalankan `php artisan test`.

**Manual QA:** login marketing → buat tagihan → (superadmin approve) → download PDF → Buat Order dari Tagihan (prefill terisi) → submit → tagihan jadi_order, order masuk pipeline; Invoice index tak error saat kosong, Download muncul saat invoice terbit.

---

## 13. Di Luar Cakupan (YAGNI)

- Auto-create order saat bayar (sengaja dibuang).
- DP/cicilan pada tagihan (pakai alur order biasa).
- Prefill penuh author/scope (enhancement masa depan; struktur teks `author_names` sudah disiapkan agar mudah ditingkatkan).
- Merge 3 link pembayaran (DP/Pelunasan/Disetujui) jadi satu list berfilter (cleanup terpisah).
- Email otomatis tagihan ke klien, scheduled job jatuh tempo, notifikasi.
- Perubahan `MarketingDashboardService`/Income (tak perlu — tagihan tak dihitung sampai jadi order).

---

## Dependensi

- DomPDF, DataTables (`datatables.net-bs4`), Spatie Permission — sudah ada, tanpa package baru.
- Alur order existing (`OrderBookController`, order create blade) — di-extend prefill, tidak dirombak.
- `PaymentBookController` auto-invoice — tidak diubah.
