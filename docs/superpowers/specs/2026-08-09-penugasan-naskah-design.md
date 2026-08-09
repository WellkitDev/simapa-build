# Penugasan Naskah v2 — Design Spec

> Menggantikan total UI modul: **Distribusi Artikel**, **Distribusi Buku**, **Manuscript Tracker**.
> Backend domain (TitleProgress, TitleProgressService, log, Notifier, ManuscriptFileService) dipertahankan dan diperluas.
>
> **Sumber kebenaran visual: `docs/wireframe-penugasan-naskah.html` (di-ACC owner 9 Agu 2026).**
> Hasil akhir HARUS sesuai wireframe tersebut — layar, label, penempatan tombol, dan bahasa.
> Sumber keputusan bisnis: `docs/kesimpulan-jawaban-tim-distribusi.md`.

## 1. Masalah

Modul lama membingungkan pengguna karena dibangun di atas model mental yang salah:
data yang sama tersebar di 4 menu tak tersambung; papan kanban hanya-baca; kartu papan
melempar ke halaman order (bukan tempat aksi); istilah "editor" tidak dikenali tim;
fitur prioritas mati (tanpa form); naskah final hilang diam-diam setelah 30 hari;
dropdown tahap menampilkan tahap yang tidak boleh dipilih; label catatan "(opsional)"
padahal wajib untuk koreksi.

**Realita operasional** (dari tim): "distribusi" = admin membagi tugas *pembuatan naskah*
ke produksi (SLA 7 hari kerja); setelah produksi upload naskah, **admin per bidang**
memegang seluruh proses sampai publish/terbit. Produksi bukan "editor" tahap-per-tahap.

## 2. Keputusan Bisnis Final (mengikat)

| # | Keputusan |
|---|-----------|
| 1 | Role aktif v1: `marketing` (view + set target), `production` (pelaksana), `admin` (PJ per bidang, aktor utama), `superadmin`. Manager: hanya penerima notifikasi (belum ada orangnya, TANPA approval flow). Akuntansi: di luar cakupan v1 |
| 2 | Dua peran per naskah: **PJ** (`pj_user_id`, harus admin, dioper hanya antar admin **sebidang**) dan **Pelaksana** (`pelaksana_user_id`, harus role production — akun admin TIDAK pernah jadi pelaksana; orang rangkap pakai 2 akun) |
| 3 | Model distribusi campuran: admin assign, ATAU produksi **claim** dari antrian tanpa pelaksana |
| 4 | **Upload naskah oleh pelaksana pada tahap Pembuatan = auto-advance ke Editing** + notifikasi PJ. Satu-satunya transisi otomatis |
| 5 | Maju tahap: **satu tombol "Selesaikan [tahap] → [tahap berikutnya]"** — TANPA dropdown semua tahap. Catatan opsional saat maju |
| 6 | Koreksi (mundur/lompat): jalur/tombol terpisah, **catatan wajib**, tercatat sebagai koreksi |
| 7 | Tahap final terbuka untuk koreksi **hanya oleh superadmin** dengan catatan wajib |
| 8 | Grup judul: aksi tahap berlaku serempak untuk semua order sejudul (`group_key`), UI SELALU menampilkan banner "berlaku untuk N order" + drill-down per order |
| 9 | Identitas utama di semua layar = **kode order**; judul pendamping; buku + nomor bab & author bab |
| 10 | Masuk antrian saat **DP terverifikasi** |
| 11 | Target publish/terbit: marketing (request klien) atau admin; tercatat di riwayat |
| 12 | Prioritas high/normal/low diset **admin** — form-nya HARUS ada (fitur lama mati) |
| 13 | Lewat SLA/target: merah + **wajib alasan** (dropdown baku: internal/eksternal/lainnya+keterangan) |
| 14 | Selesai (publish/terbit) → **Arsip** dengan filter/pencarian. TIDAK ADA auto-hilang 30 hari |
| 15 | Buku kolaborasi: **Pembuatan+Editing per bab**; **Layout→Terbit level buku**, terbuka setelah SEMUA bab Selesai; status buku = roll-up bab paling belakang. Buku mandiri: satu kesatuan tanpa bab |
| 16 | Pemetaan bab→author **wajib** sebelum bab bisa didistribusikan; bab tanpa author ditampilkan kuning "⚠ Author belum dipetakan" — tidak disembunyikan |
| 17 | Audit total: SEMUA aksi (distribusi, claim, oper, maju, koreksi, target, prioritas, upload, hold, batal, arsip) → riwayat. Tidak ada yang boleh menghapus riwayat |
| 18 | Istilah: **"editor" DIHAPUS** dari seluruh UI. Pakai "PJ" dan "Pelaksana". Menu: "Meja Kerja Saya", "Pelacakan Naskah", "Arsip Naskah" — nama sama untuk semua role. Tanpa jargon ("aging" → "sudah X hari di tahap ini") |

## 3. State Machine

```php
// TitleProgress
const ARTICLE_STAGES = ['menunggu_proses','pembuatan','editing','revisi','submit','loa','publish'];
// 'templating' dihapus (migrasi data lama → 'editing')

const BOOK_STAGES    = ['menunggu_proses','pembuatan','editing','layout','proofreading','isbn','cetak','terbit'];
// Buku MANDIRI: status langsung di progress level buku.
// Buku KOLABORASI: 'pembuatan' & 'editing' adalah status TURUNAN (roll-up) dari bab;
//                  'layout'..'terbit' adalah status level buku, terkunci sampai semua bab selesai.

// ChapterProgress (BARU)
const CHAPTER_STAGES = ['menunggu','pembuatan','editing','selesai'];

const FINAL_STAGES   = ['publish','terbit'];
// hold  : flag is_on_hold (bukan status)
// batal : cancelled_at + cancel_reason (bukan status; disembunyikan dari papan, tampil di arsip/daftar dgn filter)
```

**Roll-up buku kolaborasi** (dihitung setiap chapter_progress berubah):
ada bab `menunggu`/`pembuatan` → buku `pembuatan`; else ada bab `editing` → buku `editing`;
else (semua `selesai`) → buku tetap `editing` + flag `chapters_done=true` yang membuka tombol
"Mulai Layout" (admin menekan manual → status `layout`).

**Transisi:**
- Maju = index+1 saja. `advance()` menolak lompat.
- `menunggu_proses` + upload file `masuk` (naskah dari klien, tanpa jasa pembuatan) → langsung `editing` (lewati `pembuatan`).
- `pembuatan` + upload file `masuk` oleh pelaksana → auto `editing` (aturan #4).
- Koreksi = target ≠ index+1 → `correct()`: catatan wajib; non-superadmin tidak boleh menyentuh naskah final.
- Submit form tanpa perubahan = no-op dengan flash info ramah, BUKAN error (bug lama).

## 4. Permission Matrix (AccessMatrixSeeder — action baru `naskah.*`)

| Action | marketing | production | admin | superadmin |
|---|---|---|---|---|
| `naskah.view` (pelacakan, detail read-only, arsip) | ✓ | ✓ | ✓ | ✓ |
| `naskah.workdesk` (meja kerja saya) | — | ✓ | ✓ | ✓ |
| `naskah.target` | ✓ | — | ✓ | ✓ |
| `naskah.upload` (slot `masuk` dari klien utk marketing; semua slot utk lainnya sesuai tahap) | ✓ | ✓ | ✓ | ✓ |
| `naskah.claim` | — | ✓ | — | ✓ |
| `naskah.assign` (distribusi/tarik pelaksana, oper PJ) | — | — | ✓ (bidangnya) | ✓ |
| `naskah.advance` (selesaikan tahap) | — | — | ✓ (bidangnya) | ✓ |
| `naskah.priority` / `naskah.hold` / `naskah.cancel` | — | — | ✓ (bidangnya) | ✓ |
| `naskah.correct` (mundur/lompat; termasuk final) | — | — | — | ✓ |

**Scoping bidang:** `user_profiles.bidang` (`artikel`|`buku`|null). Gate tambahan di service:
aksi admin hanya sah bila `progress.bidang === actor.bidang` (superadmin bebas). Oper PJ hanya
ke admin dengan bidang sama.

## 5. Perubahan Data

### `tb_title_progress` (additive)
`pj_user_id` FK users nullable · rename `assigned_user_id`→`pelaksana_user_id` ·
`bidang` string(10) · `sla_due_at` date nullable · `overdue_reason` string(30) nullable ·
`overdue_note` text nullable · `is_on_hold` bool default 0 · `chapters_done` bool default 0 ·
`archived_at` timestamp nullable · `cancelled_at`/`cancelled_by`/`cancel_reason` nullable.

### `tb_chapter_progress` (additive)
`pelaksana_user_id` FK nullable · `sla_due_at` date nullable · status mengikuti CHAPTER_STAGES.

### `manuscript_files.slot`
Perluas enum: `masuk`, `hasil_editing`, `hasil_layout`, `hasil_proofread`, `cover`, `final`.

### `title_progress_logs.event` (nilai baru, struktur tetap)
`distribusi`, `claim`, `oper_pj`, `tarik_tugas`, `hold`, `unhold`, `dibatalkan`,
`diarsipkan`, `overdue_reason_set`, `auto_advance_upload`, `chapters_done`.

## 6. Layar (WAJIB mengikuti `docs/wireframe-penugasan-naskah.html`)

### Layar 1 — `naskah/meja-kerja` (production, admin, superadmin)
4 kartu statistik (Tugas Aktif, Terlambat-merah, Deadline Minggu Ini, Selesai Bulan Ini).
Daftar "Tugas Saya" urut: overdue → deadline terdekat → prioritas. Baris = kode order,
judul, meta (jenis/bab/author/PJ), chip tahap, "hari ke-X dari SLA 7 hari" / "sudah X hari
di tahap ini", target, tombol aksi kontekstual (produksi: "⬆ Upload Naskah"; admin:
"✓ Selesaikan Tahap →"). Baris overdue: latar merah muda + border merah.
Seksi "Antrian Belum Ditugaskan — bisa diambil": naskah `menunggu_proses` tanpa pelaksana
dengan tombol "✋ Ambil Tugas Ini" (produksi).

### Layar 2 / 2B — `naskah/pelacakan` (?tipe=artikel|buku, ?view=papan|daftar|riwayat)
Toolbar: tab Artikel|Buku, filter PJ/Pelaksana, prioritas, pencarian, toggle
Papan|Daftar|Riwayat. Papan artikel 3 zona: Antrian(Menunggu) / Produksi(Pembuatan,
Editing, Revisi, Submit) / Finalisasi(LoA, Publish). Papan buku 4 zona: Antrian /
Produksi per Bab(Pembuatan, Editing) / Produksi Level Buku(Layout, Proofread, ISBN) /
Finalisasi(Cetak, Terbit). Kartu: kode order (utama), judul, badge "N order" bila grup,
PJ + Pelaksana, "X hari di tahap ini", target (merah bila lewat + alasan), prioritas.
Kartu buku kolaborasi: ringkasan bab "✓2 selesai · 1 editing · 1 pembuatan · 6 menunggu"
+ progress bar; duduk di kolom bab paling belakang. **Klik kartu → Detail Naskah** (bukan
halaman order). Papan read-only untuk marketing. View Riwayat: log per tipe (DataTable).

### Layar 3 / 3B — `naskah/{orderDetail}` (halaman kanonik)
Header: kode order besar + chips (jenis, indeksasi/jml bab, prioritas) + banner grup
"mencakup N order … aksi berlaku serempak · lihat per order ▾".
Stepper timeline dengan tanggal & durasi per tahap; tahap aktif menampilkan "X hari — sejak {tgl}".
Kiri: kartu Informasi (PJ, Pelaksana, bidang, target+asal request, prioritas+siapa+kapan,
status pembayaran), kartu Brief dari Marketing, kartu File (per slot, versi, uploader, tanggal).
Kanan: kartu Aksi **hanya untuk role berwenang** — tombol besar "✓ Selesaikan {tahap} →
{berikutnya}", textarea catatan "(opsional saat maju normal)", tombol "↩ Perlu Revisi
(wajib pilih alasan)", "⇄ Koreksi tahap (wajib catatan)" (superadmin utk final), form ganti
pelaksana/prioritas/target, "✕ Batalkan (wajib alasan)". Kartu Riwayat.
**3B (buku kolaborasi):** + pintasan level buku (terapkan 1 pelaksana ke semua bab, struktur
bab), tabel bab kolom: No | Judul Bab | **Author (naskah dari siapa)** | Pelaksana | Status |
Lama | Aksi per bab. Bab tanpa author: baris kuning + tombol "Petakan Author", tombol
distribusi disabled. File level buku terpisah dari file bab.

### Arsip — `naskah/arsip`
DataTable naskah `archived_at != null` + dibatalkan (filter), pencarian kode/judul, link detail.

## 7. Notifikasi (Notifier — method baru/dipakai ulang)

| Peristiwa | Penerima |
|---|---|
| Distribusi/tarik tugas | Pelaksana ybs |
| Claim | PJ (jika ada) + admin bidang |
| Upload naskah (auto-advance) | PJ |
| Maju tahap / koreksi | PJ + superadmin |
| Oper PJ | Admin penerima |
| Lewat SLA / target (command harian) | PJ + pelaksana + superadmin |
| Publish/Terbit | Marketing pemilik tiap order dalam grup |

Command terjadwal: `naskah:check-overdue` (daily) — tandai overdue, kirim notifikasi,
minta alasan bila belum diisi.

## 8. Migrasi Data (command `naskah:migrate-v2`)

1. `templating` → `editing` + log koreksi "migrasi v2" oleh sistem.
2. `bidang` diisi dari `order_details.type` (bk_* → buku, selainnya artikel).
3. `assigned_user_id` berisi admin → jadi `pj_user_id`, `pelaksana_user_id` NULL; berisi
   production → `pelaksana_user_id` tetap, `pj_user_id` NULL (diisi admin lewat UI —
   sediakan filter "belum ada PJ" di pelacakan).
4. Naskah `publish`/`terbit` → `archived_at = now()`.
5. Chapter status lama → CHAPTER_STAGES (`menunggu_proses`→`menunggu`; `editing`/`layout`/
   `proofreading`/`isbn`/`cetak`→`editing`; `terbit`/`publish`→`selesai`), lalu hitung roll-up.
6. Semua additive; snapshot DB sebelum eksekusi; kolom lama tidak di-drop sampai cleanup.

## 9. Non-Goals v1

Approval manager · multi-PIC per tahap · tahap paralel (ISBN ∥ layout) · progres persentase ·
notifikasi WhatsApp/email · akses akuntansi · workflow jurnal eksternal terpisah.

## 10. Kriteria Penerimaan

1. Produksi login → di Meja Kerja langsung tahu tugas, mana yang telat, dan satu tombol aksi jelas.
2. Upload naskah pada tahap Pembuatan → status otomatis Editing, PJ dapat notifikasi, log `auto_advance_upload`.
3. Halaman detail hanya menampilkan SATU tombol maju; submit tanpa perubahan bukan error.
4. Tabel bab menampilkan author per bab; bab tanpa author kuning dan tak bisa didistribusikan.
5. Tombol "Mulai Layout" terkunci sampai semua bab Selesai.
6. Marketing melihat semuanya read-only; dapat notifikasi saat publish/terbit.
7. Naskah selesai ada di Arsip dan bisa dicari — tidak pernah hilang.
8. Koreksi naskah final hanya bisa superadmin + catatan; tercatat di riwayat.
9. Aksi pada grup N order → banner tampil, ketiga progress berubah + N baris log.
10. Tidak ada kata "editor"/"tracker"/"aging" di UI; kartu papan link ke Detail Naskah.
11. Suite test tetap hijau (`php artisan test`, DB `.env.testing`).
