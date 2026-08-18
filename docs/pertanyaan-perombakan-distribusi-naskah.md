# Daftar Pertanyaan — Perombakan Modul Distribusi & Pelacakan Naskah

> Tujuan: jawab semua pertanyaan ini dulu, baru desain ulang. Setiap fitur di UI baru
> harus bisa menjawab 5W1H tanpa user bertanya-tanya.
>
> Konteks role: **marketing** (view saja), **admin & produksi** (aktor, bisa semua),
> **manager**, **akuntansi**, **superadmin**.

---

## A. SIAPA — Aktor & Wewenang

1. Siapa yang **mendistribusikan** naskah (menunjuk editor/PIC)? Admin saja? Manager? Atau produksi boleh ambil sendiri dari antrian (sistem claim)?
2. Siapa yang **meng-update progres** tahap? Hanya editor yang ditugaskan, atau semua orang produksi/admin?
3. Siapa **yang didistribusikan** (penerima tugas)? Hanya user role produksi? Apakah admin juga bisa jadi editor?
4. Bolehkah satu naskah punya **lebih dari satu PIC** (mis. editor bahasa ≠ layouter ≠ proofreader)? Atau satu PIC dari awal sampai akhir?
5. Apakah editor boleh **melempar/mengoper tugas** ke editor lain? Perlu persetujuan siapa?
6. Kalau editor cuti/resign/overload, siapa yang **merealokasi** tugasnya? Ada tampilan beban kerja per editor untuk dasar keputusan?
7. Manager: hanya **memantau**, atau juga ikut eksekusi (assign, pindah tahap)?
8. Superadmin: boleh **koreksi mundur** naskah yang sudah publish/terbit? (Kode sekarang: tidak bisa — tahap final terkunci untuk semua.)
9. Marketing view-only — tapi kode sekarang mengizinkan marketing **set target tanggal**. Dicabut atau dipertahankan?
10. Akuntansi perlu **akses modul ini** tidak? Kalau ya, lihat apa (mis. status terbit untuk penagihan)?
11. Siapa yang berhak **membatalkan** distribusi (menarik naskah dari editor)?
12. Kalau ada perbedaan pendapat (editor bilang selesai, manager bilang belum), siapa **pemutus akhir** per tahap?

## B. APA — Objek yang Didistribusikan

13. Unit distribusi **artikel** = per judul atau per order? (Sekarang: per grup judul — semua order berjudul sama bergerak serempak.) Apakah perilaku grup ini dipertahankan dan **ditampilkan jelas** ke user?
14. Unit distribusi **buku kolaborasi** = per bab? Tiap bab bisa beda editor dan beda tahap? (Sekarang: ya, tapi UI-nya membingungkan.)
15. **Buku mandiri** (1 author) — perlu dipecah per bab juga, atau cukup satu kesatuan buku?
16. **[Contoh kasus kamu]** Pada distribusi per bab, bagaimana cara tahu **bab itu artikel/naskah dari author siapa**? Siapa yang meng-input pemetaan bab → author, dan kapan (saat order dibuat? saat naskah masuk)? Apakah pemetaan itu sudah pasti terisi, atau sering kosong?
17. Kalau satu author ikut **beberapa bab**, bagaimana menampilkannya?
18. Kalau bab **belum ada judulnya** saat order (klien belum kirim naskah), bab ditampilkan sebagai apa?
19. Apa **identitas utama** naskah di mata tim: kode order (ORD-xxx), kode judul, atau judul teks? Semua layar harus konsisten pakai yang mana?
20. Naskah **jurnal internal vs eksternal** (submit ke jurnal luar) — alurnya sama atau beda?

## C. KAPAN — Waktu, Target, Prioritas

21. Kapan naskah **masuk antrian distribusi**? Setelah order dibuat? Setelah DP? Setelah lunas? Setelah file naskah diterima?
22. Siapa yang menetapkan **target publish/terbit**, dan berdasarkan apa (janji ke klien, kapasitas tim)?
23. Ada **SLA per tahap** (mis. editing maksimal X hari kerja)? Berapa untuk tiap tahap?
24. Kalau lewat SLA/target, **apa yang terjadi**: warna merah saja, notifikasi ke manager, eskalasi otomatis?
25. **Prioritas** (high/normal/low) ditentukan siapa, berdasarkan apa? (Sekarang: fiturnya ada di backend tapi tidak ada form-nya sama sekali — mati.)
26. Berapa lama naskah selesai boleh **tetap tampil** di papan sebelum diarsip? (Sekarang: 30 hari, tersembunyi, bikin user mengira data hilang.)
27. Perlu tampilan **"sudah berapa lama di tahap ini"** (aging) di setiap kartu/baris?

## D. DI MANA — Lokasi Informasi & File

28. **Satu naskah, satu halaman**: apakah setuju semua info (status, editor, file, riwayat, catatan) dikumpulkan di SATU halaman detail, bukan tersebar di 4 menu seperti sekarang?
29. File naskah disimpan di slot **"masuk" dan "final"** saja — cukup? Atau perlu file per tahap (hasil editing, hasil layout, hasil proofread)?
30. Siapa yang **meng-upload file masuk** dari klien: marketing, admin?
31. File **per bab** dan **per buku** dua-duanya perlu? Siapa pakai yang mana?
32. Perlu integrasi **Google Drive** (service-nya sudah ada di kode) atau cukup storage lokal?
33. Di mana editor melihat **catatan/instruksi khusus** dari marketing tentang permintaan klien?
34. Di mana marketing melihat **status naskah kliennya** — cukup kolom status di daftar order, atau halaman khusus?

## E. MENGAPA — Alasan & Akuntabilitas

35. Setiap perpindahan tahap perlu **catatan wajib** atau opsional? (Sekarang: opsional untuk maju, wajib untuk koreksi — tapi UI menulis "opsional" untuk semua. Bug.)
36. Perlu **alasan baku (dropdown)** untuk revisi/koreksi supaya bisa direkap (mis. "revisi reviewer", "salah template", "permintaan klien")?
37. Riwayat (log) per naskah **siapa yang boleh lihat**? Marketing boleh lihat riwayat naskah kliennya?
38. Perlu **bukti kerja** saat menyelesaikan tahap (upload file hasil / link) atau cukup klik?
39. Kenapa dulu **drag & drop di papan dicabut**? Masalah apa yang terjadi? (Penting supaya solusi baru tidak mengulang.)

## F. BAGAIMANA — Alur Kerja

40. **Bagaimana proses distribusi terjadi**: (a) admin/manager assign satu-satu, (b) editor ambil sendiri dari pool antrian, atau (c) campuran (admin assign, editor boleh claim yang belum ada pemiliknya)?
41. **Bagaimana editor tahu ada tugas baru**: notifikasi in-app saja (sudah ada), atau perlu WhatsApp/email/Telegram?
42. **Bagaimana editor melihat tugasnya**: halaman "Meja Kerja Saya" berisi daftar tugas berurut prioritas + deadline? Apa saja kolomnya?
43. **Bagaimana editor meng-update progres**: tombol besar "Selesaikan tahap ini → lanjut ke X" di halaman detail? Drag kartu di papan? Keduanya?
44. Perlu **progres parsial dalam satu tahap** (mis. editing 60%, atau ceklis sub-tugas) atau cukup pindah tahap?
45. **Bagaimana alur revisi**: editing → revisi → balik ke editing lagi? Berapa kali boleh bolak-balik? Dihitung/direkap?
46. **Bagaimana verifikasi**: apakah manager harus approve sebelum naskah lanjut ke tahap berikutnya, atau editor langsung majukan sendiri?
47. **Bagaimana menangani naskah batal/refund** di tengah proses — status khusus "dibatalkan"?
48. **Bagaimana tahap yang tidak relevan** untuk naskah tertentu di-skip (mis. artikel tanpa templating)? Boleh skip dengan catatan?
49. Untuk buku: kalau **semua bab selesai editing**, apakah status buku naik otomatis (roll-up), atau manual?
50. **Bagaimana urutan tahap buku vs kenyataan**: menunggu → editing → layout → proofreading → ISBN → cetak → terbit. Sudah benar? ISBN sering paralel dengan layout — perlu tahap paralel?
51. **Bagaimana urutan tahap artikel**: menunggu → templating → editing → revisi → submit → LoA → publish. Sudah sesuai? "Revisi" posisinya memang selalu setelah editing?
52. **Bagaimana kartu di papan menampilkan grup**: kalau 3 order sejudul beda status, tampilkan status termundur (sekarang), atau pecah per order?
53. **Bagaimana notifikasi ke marketing** saat naskah kliennya publish/terbit, supaya bisa kabari klien?
54. **Bagaimana manager memantau**: dashboard berisi apa — beban per editor, naskah telat, throughput per minggu, bottleneck per tahap?

## G. Istilah & Bahasa

55. Sepakati istilah: **"Distribusi"** diganti jadi "Penugasan Naskah"? (Kata "distribusi" bentrok dengan "Distribusi Profit" di menu akuntansi.)
56. Nama menu **konsisten untuk semua role**? (Sekarang: "Meja Kerja Saya" untuk produksi, "Pelacak Naskah" untuk lainnya, judul halaman "Manuscript Tracker" — tiga nama untuk satu halaman.)
57. Nama tahap dibakukan bahasa Indonesia semua?

## H. Data & Migrasi

58. Data progres 2 minggu berjalan **dipertahankan** dan dimigrasi ke struktur baru?
59. Ada naskah yang statusnya **sudah kacau/salah** sekarang? Perlu koreksi masal saat migrasi?
60. Modul lama **langsung diganti** atau jalan paralel sebentar?

---

## Jawaban yang sudah pasti dari kode (tidak perlu ditanya lagi)

- Tahap final (publish/terbit) terkunci permanen untuk semua role, termasuk superadmin.
- Aksi pada satu judul otomatis berlaku ke SEMUA order berjudul sama (group sync).
- Naskah final hilang dari papan setelah 30 hari.
- Prioritas: backend lengkap, UI tidak ada → tidak pernah terpakai.
- Marketing saat ini bisa set target date (mungkin tidak disengaja).
- Papan kanban dulunya bisa drag, sekarang read-only.
