# Kesimpulan Jawaban Tim (Fitri & Ria) — Perombakan Penugasan Naskah

> Catatan pembacaan: jawaban **Fitri** menggambarkan *bagaimana pekerjaan benar-benar
> berjalan hari ini* (ground truth operasional). Jawaban **Ria** lebih berupa *desain
> ideal/best practice*. Keduanya dipakai: realita Fitri menentukan alur inti,
> prinsip Ria menentukan kualitas (audit log, arsip, aging).

---

## 1. TEMUAN TERBESAR — Model mental sistem sekarang SALAH

Dari jawaban Fitri terbaca alur kerja nyata yang berbeda dari yang dimodelkan sistem:

**Realita di lapangan:**
1. Order masuk, setelah **DP** naskah masuk antrian.
2. Jika layanan termasuk **pembuatan naskah** → admin mendistribusikan tugas *menulis*
   ke **produksi** (SLA 7 hari kerja). Produksi menulis, lalu **upload naskah** →
   status otomatis maju ke editing.
3. Setelah naskah ada, **admin yang memegang seluruh proses** (editing → publish/terbit).
   Produksi selesai perannya setelah menyerahkan naskah.

**Yang dimodelkan sistem sekarang:** "editor" (role produksi) ditugaskan per naskah dan
menggerakkan naskah melewati tahap-tahap produksi.

Kesimpulan: **"Distribusi" di bisnis ini = pembagian tugas PEMBUATAN naskah ke produksi
+ pembagian tanggung jawab PROSES ke admin per bidang.** Bukan "assign editor" generik.
Ini akar kenapa user bingung — kosakata dan alur sistem tidak cocok dengan pekerjaan mereka.
(Bukti tambahan: Fitri sampai bertanya *"editor ni apa?"* — istilah inti fitur saja
tidak dikenali penggunanya.)

Konsekuensi desain: tahap **"Pembuatan Naskah"** perlu ada (opsional, hanya untuk order
yang naskahnya dibuatkan) sebelum tahap editing. Tahap ini yang jadi objek distribusi
ke produksi.

## 2. Struktur tim nyata (dari jawaban Fitri)

- Admin dibagi **per bidang**: buku → Ipit; artikel SINTA → Pia & Ria.
- Orang yang sama merangkap admin + produksi → **minta akun dipisah**
  (mis. "Fitri Admin" dan "Fitri Produksi") supaya permission bersih dan siap
  jika nanti ada produksi murni.
- **Role manager belum ada orangnya**. Akuntansi tidak paham modul ini.
- Oper tugas hanya masuk akal **antar admin satu bidang** (Pia ↔ Ria).
- Kalau admin overload: naskah di-hold atau dioper ke freelance.

## 3. KESEPAKATAN (kedua responden searah) — langsung jadi keputusan

| # | Keputusan | Sumber |
|---|-----------|--------|
| 1 | **Admin adalah penanggung jawab utama** distribusi & proses; admin yang membatalkan/menarik tugas | F1, F11, R1, R11 |
| 2 | **Identitas utama naskah = kode order** (judul sebagai pendamping; buku: + nomor bab) | F19, R19 |
| 3 | **Buku kolaborasi per bab** (tiap bab bisa beda pelaksana & status); fallback 1 buku utuh boleh jika 1 orang pegang semua bab | F14, R14 |
| 4 | **Buku mandiri = satu kesatuan**, tidak dipecah per bab | F15, R15 |
| 5 | **Superadmin boleh koreksi naskah final**, wajib tercatat di riwayat — tidak ada perubahan diam-diam | F8, R8 |
| 6 | **Riwayat/audit log untuk SEMUA aksi tanpa kecuali** (pindah tahap, koreksi, oper, batal, skip) | F8, R6 |
| 7 | **Naskah selesai tidak boleh hilang tiba-tiba**: tampil sampai publish/terbit, lalu pindah ke arsip dengan filter — bukan lenyap setelah 30 hari | F26, R26 |
| 8 | **Prioritas ditentukan admin** — form-nya harus dibuat (sekarang mati) | F25, R25 |
| 9 | **Masuk antrian setelah DP** (+ syarat file naskah tersedia bila tidak dibuatkan) | F21, R21 |
| 10 | **Lewat target = merah + wajib catatan alasan** (internal/eksternal → dropdown alasan baku) | F24, R24, R36 |
| 11 | **Target ditetapkan berdasar request klien** (marketing menyampaikan), admin jika tidak ada request | F22, R22 |
| 12 | **Update progres via tombol aksi di halaman detail**; drag & drop bukan jalur utama | R43 + keputusan sebelumnya |
| 13 | **Satu halaman detail per naskah** berisi status, PIC, file, brief marketing, riwayat | R28, R33 |
| 14 | **Istilah disederhanakan**: hapus kata "editor"; pakai **Admin** dan **Produksi** saja. "Distribusi" → **"Penugasan Naskah"**; nama menu satu untuk semua role | F5, F12, R55, R56 |
| 15 | **Sedikit role, sedikit istilah** — jangan tambah role/konsep baru sebelum orangnya ada | F12, R (implisit) |
| 16 | **Notifikasi ke marketing saat naskah publish/terbit** (untuk kabari klien) | R53, selaras F |
| 17 | **Bukti kerja**: upload naskah oleh produksi = pemicu maju tahap; tahap penghasil output sebaiknya lampirkan file | F2, R38 |
| 18 | **SLA pembuatan naskah = 7 hari kerja**; tahap lain menyesuaikan target publish | F23 |

## 4. KONFLIK — SUDAH DIPUTUSKAN owner (Rahmat, 9 Agu 2026)

| # | Isu | Fitri | Ria | ✅ KEPUTUSAN |
|---|-----|-------|-----|--------------|
| K1 | Marketing set target tanggal | Dipertahankan | Dicabut, ajukan via admin | **Dipertahankan** — target datang dari request klien via marketing; semua perubahan tercatat di riwayat |
| K2 | PIC per naskah | Admin pegang end-to-end, produksi hanya menyerahkan naskah | Multi-PIC per tahap (editor≠layouter≠proofreader) | **Model sederhana**: 1 Penanggung Jawab (admin bidang) + 1 Pelaksana (produksi, saat tahap pembuatan/bab). Multi-PIC = v2 jika tim membesar |
| K3 | Approval manager | Tidak tahu, role kosong | Manager approver tahap kritis | **Tanpa approval — hanya NOTIFIKASI**: perpindahan tahap langsung jalan, manager/superadmin menerima notifikasi setiap perpindahan penting. Tidak ada blocking |
| K4 | Unit artikel & grup judul | Per judul | Per order, jangan paksa satu status | **Grup transparan + drill-down**: aksi per grup judul (kerja tidak dobel), kartu menampilkan "N order", bisa dibuka per order |
| K5 | Workflow: tahap paralel & revisi fleksibel (ISBN ∥ layout; revisi setelah submit) | — | Perlu | **v1: linier dengan koreksi bebas + catatan** (sudah didukung service sekarang). Paralel ditunda |

## 5. Pertanyaan yang GAGAL dipahami responden = pelajaran desain

Fitri tidak paham: "editor", "akuntansi/modul", "aging", "alur internal vs eksternal",
"menampilkan apa?". Artinya UI baru **tidak boleh memakai istilah-istilah itu tanpa
penjelasan**. Setiap label harus lolos uji "apakah Fitri langsung paham?" —
mis. bukan "aging" tapi **"sudah 3 hari di tahap ini"**.

## 6. Kerangka solusi (hasil sintesis)

**Role & hak (v1):**

| Aksi | Marketing | Produksi | Admin | Superadmin |
|------|-----------|----------|-------|------------|
| Lihat semua naskah | ✔ (read-only) | ✔ | ✔ | ✔ |
| Set target (request klien) | ✔ | — | ✔ | ✔ |
| Distribusi/tarik tugas ke produksi | — | — | ✔ | ✔ |
| Upload naskah (memicu maju tahap) | — | ✔ | ✔ | ✔ |
| Majukan tahap proses | — | — | ✔ (bidangnya) | ✔ |
| Oper antar admin sebidang | — | — | ✔ | ✔ |
| Prioritas, hold, batalkan | — | — | ✔ | ✔ |
| Koreksi mundur (termasuk final) | — | — | — | ✔ (wajib catatan) |

**Alur naskah (artikel, order dengan jasa pembuatan):**
`Menunggu (setelah DP)` → `Pembuatan Naskah (produksi, SLA 7 hari)` → *upload* →
`Editing` → `Revisi` → `Submit` → `LoA` → `Publish` → *arsip (filter, bukan hilang)*
— order tanpa jasa pembuatan langsung lompat ke Editing saat file masuk.

**Tiga layar inti:**
1. **Meja Kerja Saya** (produksi & admin): tugasku, urut overdue → deadline → prioritas.
2. **Pelacakan Naskah** (semua role): papan/daftar monitoring, kartu menjawab 5W1H
   (kode order, judul, bidang, PIC, tahap + sudah berapa hari, target, N order).
3. **Detail Naskah** (satu halaman kanonik): identitas + brief marketing + timeline
   tahap + tombol aksi sesuai role + file per tahap + riwayat lengkap.

## 7. Yang masih harus diputuskan di meeting singkat

- Angka SLA per tahap selain pembuatan naskah (jika mau).
- Kebijakan gate pembayaran selain DP (mis. cetak baru boleh setelah lunas?).
- Kanal notifikasi tambahan (WhatsApp/email) — perlu integrasi & biaya.
- Pemisahan akun rangkap admin/produksi: eksekusi teknisnya (buat user baru per orang).
