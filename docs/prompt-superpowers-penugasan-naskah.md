# Prompt untuk Superpowers (Claude Code di VS Code)

## Prompt utama — mulai eksekusi plan

```
Gunakan skill superpowers:executing-plans (atau superpowers:subagent-driven-development)
untuk mengeksekusi plan berikut task-by-task:

docs/superpowers/plans/2026-08-09-penugasan-naskah.md

Konteks wajib dibaca sebelum mulai:
1. Spec desain : docs/superpowers/specs/2026-08-09-penugasan-naskah-design.md
2. Wireframe   : docs/wireframe-penugasan-naskah.html  ← SUMBER KEBENARAN VISUAL.
   Hasil setiap layar HARUS cocok dengan wireframe ini: struktur, label, penempatan
   tombol, warna status, dan bahasa.
3. Keputusan bisnis: docs/kesimpulan-jawaban-tim-distribusi.md

Aturan eksekusi:
- Kerjakan berurutan mulai Task 1. Centang checkbox di file plan setiap step selesai.
- Setiap selesai satu task: jalankan `php artisan test` (pakai .env.testing / DB
  avidpedi_simapa_test, JANGAN DB asli) — suite harus hijau sebelum lanjut task berikutnya.
- JANGAN kerjakan Task 14 (cleanup/penghapusan modul lama) — task itu menunggu keputusan
  owner setelah masa transisi. Berhenti setelah Task 13 + Verifikasi Akhir.
- Modul lama (Distribusi Artikel/Buku, Manuscript Tracker) harus tetap berfungsi selama
  pengerjaan — semua migration additive, tidak ada drop kolom/tabel.
- Dilarang menulis kata "editor", "tracker", "aging" di UI/label/flash message.
  Gunakan: "PJ", "Pelaksana", "sudah X hari di tahap ini". Semua teks UI bahasa Indonesia.
- Identitas utama naskah di semua layar = kode order.
- Jika menemukan ambiguitas antara plan, spec, dan wireframe: wireframe menang untuk
  urusan visual/label, spec menang untuk urusan aturan bisnis. Jika tetap ragu, TANYA,
  jangan mengasumsikan.
- Commit per task dengan pesan: "penugasan-naskah: Task N — <ringkasan>".

Mulai dari: baca plan + spec + wireframe, lalu kerjakan Task 1.
```

## Prompt lanjutan — melanjutkan sesi berikutnya

```
Lanjutkan eksekusi docs/superpowers/plans/2026-08-09-penugasan-naskah.md dengan skill
superpowers:executing-plans. Periksa checkbox di file plan untuk tahu task/step terakhir
yang selesai, verifikasi `php artisan test` hijau, lalu lanjut ke step berikutnya.
Aturan eksekusi sama seperti sebelumnya (lihat bagian "Aturan eksekusi" di
docs/prompt-superpowers-penugasan-naskah.md). Jangan kerjakan Task 14.
```

## Prompt verifikasi akhir (setelah Task 13)

```
Semua task 1-13 selesai. Jalankan bagian "Verifikasi Akhir" di
docs/superpowers/plans/2026-08-09-penugasan-naskah.md:
1. `php artisan test` — hijau penuh, AccessParityTest mencakup semua route naskah.*
2. Verifikasi 11 kriteria penerimaan di spec §10 satu per satu — buat akun uji
   (marketing, produksi, admin artikel, admin buku, superadmin) via seeder/tinker,
   jalankan skenario end-to-end: DP → antrian → distribusi → upload (auto-advance) →
   editing → ... → publish → arsip; termasuk skenario buku kolaborasi per bab.
3. Bandingkan setiap layar dengan docs/wireframe-penugasan-naskah.html dan laporkan
   perbedaan sekecil apa pun dalam tabel: [Layar | Elemen | Wireframe | Implementasi].
Laporkan hasilnya sebagai checklist lulus/gagal per kriteria.
```
