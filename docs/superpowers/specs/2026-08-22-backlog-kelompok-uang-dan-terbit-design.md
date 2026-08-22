# Backlog: Kelompok Uang & Kelompok Terbit

> Lanjutan dari audit `2026-08-20-sinkronisasi-status-order-naskah-design.md` §9
> ("Yang sengaja ditinggalkan"). Tulang Punggung sudah tuntas; A1 ditutup sesi lain
> 2026-08-22 (`b21f761`). Yang tersisa dikumpulkan di sini, masing-masing dengan
> bentuk yang sama: **belum apa → fungsinya apa → rencananya apa → seharusnya begini.**
>
> Semua temuan di bawah diverifikasi ulang terhadap kode pada 2026-08-22, bukan
> disalin dari dokumen audit. Nomor baris menunjuk keadaan hari itu.
>
> **STATUS 2026-08-22 (sesi lanjutan): urutan 1–7 SELESAI.** A13, A3, A4, A10,
> kebocoran tanggal, A5, dan A8 sudah dikerjakan dan hijau di suite penuh
> (1347 lolos). Hanya **A9** yang tersisa — dan ia memang ditandai "jangan langsung
> dikerjakan". Catatan hasil verifikasi ada di masing-masing bagian di bawah.

---

## Ringkasan urutan kerja

| Urutan | Item | Kelompok | Ukuran | Kenapa di urutan itu |
|---|---|---|---|---|
| 1 | A13 — `Payment::$dates` mati | Uang | ~1 baris | Bug tampil, perbaikannya sepele, tak bergantung apa pun |
| 2 | A3 — lunas sebelum approval | Uang | sedang | Menetapkan arti "uang yang sah"; A4, A10, dan kebocoran tanggal menempel padanya |
| 3 | A4 — biaya diedit tak hitung ulang | Uang | kecil | Gratis kalau A3 dikerjakan lewat satu fungsi |
| 4 | A10 — konstanta status | Uang | kecil | Menyentuh berkas yang sama dengan A3 |
| 5 | Kebocoran tanggal terbit → "Tanggal Lunas" | Uang | kecil | Butuh definisi "payment disetujui" dari A3 |
| 6 | A5 — invoice jatuh tempo | Uang | sedang | Berdiri sendiri, boleh kapan saja |
| 7 | A8 — penanda terbit di judul | Terbit | kecil | Rekomendasi: **tutup sebagai keputusan**, bukan bangun kolom |
| 8 | A9 — urutan tahap jurnal | Terbit | besar | Paling berisiko; **butuh brainstorming sendiri dulu** |

---

# KELOMPOK UANG

Satu kalimat yang menaungi seluruh kelompok ini: **`tb_orders.status` adalah keadaan
uang, dan hari ini ia bisa berbohong.** Ia bilang "lunas" untuk uang yang belum
diverifikasi, bilang "lunas" untuk DP, dan tak berubah saat harganya berubah.
Semua item di bawah adalah versi berbeda dari kalimat itu.

Yang **tidak** terpengaruh, dan itu disengaja: `fulfillment_status` (keadaan pekerjaan)
dipisahkan justru supaya bug-bug di sini tak menular ke pembacaan "order ini masih
hidup". Arsip, papan naskah, dan penarikan refund tetap benar.

---

## A13 — `Payment::$dates` mati, kolom Tanggal kosong di seluruh laporan pemasukan

**Temuan baru 2026-08-22, tidak ada di daftar audit.**

### Belum apa

`app/Models/Payment.php:21` masih memakai:

```php
protected $dates = ['paid_at'];
```

`protected $dates` **tidak berfungsi lagi sejak Laravel 10**. Diverifikasi langsung di
`vendor/laravel/framework/.../HasAttributes.php:1487` — `getDates()` mengembalikan
hanya `created_at`/`updated_at` dan tak pernah melirik properti `$dates`. Aplikasi ini
berjalan di Laravel 10.50.0.

Akibatnya `$payment->paid_at` kembali sebagai **string**, bukan Carbon.

### Fungsinya apa

`paid_at` adalah tanggal uang benar-benar masuk. Ia sumber tunggal untuk pertanyaan
"pemasukan bulan ini berapa" dan untuk kolom Tanggal di tiap daftar pembayaran.

### Kenapa ini lolos begitu lama

Karena kerusakannya **senyap dan sebagian**. Pola yang dipakai di mana-mana adalah:

```php
optional($p->paid_at)->format('d M Y') ?? '-'
```

`optional()` pada sebuah string mengembalikan pembungkus yang, saat dipanggil
methodnya, diam-diam mengembalikan `null` — tidak melempar galat. Jadi kolomnya
menampilkan `-`, bukan pesan error.

Dan yang **benar** tetap benar: seluruh KPI, grafik tahunan, dan grafik bulanan di
`FinancialReportService::pemasukan()` memakai `whereYear('paid_at')` serta
`selectRaw('YEAR(paid_at)...')` — itu SQL, sama sekali tak lewat cast Eloquent.
Jadi angkanya benar; hanya tanggal per baris yang kosong. Laporan yang "hampir benar"
jauh lebih sulit dicurigai daripada laporan yang jelas rusak.

Ada jejak bahwa seseorang pernah menabrak ini dan menambalnya di tempat, bukan di
akarnya: `resources/views/payments/invoices/book_invoice_pdf.blade.php:325` memakai
`\Carbon\Carbon::parse($payment->paid_at)` — satu-satunya tempat yang tanggalnya muncul.

### Yang terdampak

| Berkas | Akibat |
|---|---|
| `resources/views/income/pemasukan.blade.php:81` | Kolom Tanggal `-`; `data-order` jadi `0` sehingga **urutan tanggal DataTables juga mati** |
| `resources/views/income/pdf/pemasukan.blade.php:16` | Kolom Tanggal `-` di PDF |
| `app/Http/Controllers/Pages/IncomeController.php:47` | Kolom Tanggal kosong di ekspor CSV |
| `resources/views/payments/refunds/refund_pdf.blade.php:29` | Tanggal refund kosong di PDF |
| `app/Services/ProfitAnalysisService.php:142` | Kolom `tanggal` kosong di Analisa Profit |

### Rencananya

Satu baris, plus pagar supaya tak kambuh:

```php
protected $casts = [
    'paid_at' => 'datetime',
];
```

Tes: `assertInstanceOf(Carbon::class, $payment->paid_at)` — dan satu tes layar yang
benar-benar melihat tanggalnya tampil di `/keuangan/pemasukan`, karena tes unit saja
tak akan menangkap `optional()->format()` yang balik null.

### Seharusnya

Setiap kolom waktu di setiap model dideklarasikan di `$casts`. `protected $dates`
tidak boleh ada di berkas mana pun. `Order`, `TitleProgress`, dan `ServiceInvoice`
sudah dibersihkan dan diberi komentar peringatan; `Payment` satu-satunya yang tertinggal.

---

## A3 — Order jadi `lunas` sebelum pembayaran disetujui

### Belum apa

Di `app/Http/Controllers/Pages/PaymentBookController.php:135-176`, satu transaksi
melakukan semuanya sekaligus:

```php
$payment = Payment::create([... 'status' => 'paid']);      // baris 141
PaymentApproval::create([... 'status' => 'pending']);      // baris 167
if ($payment->payment_type === 'lunas' || $payment->payment_type === 'pelunasan') {
    $order->update(['status' => 'lunas']);                 // baris 172
}
```

Pembayaran lahir berstatus `paid` **dan** approval-nya lahir berstatus `pending`, di
transaksi yang sama. Ordernya langsung `lunas`.

Ada **tiga** kesalahan tertumpuk di sini, dan penting membedakannya karena
perbaikannya beda:

1. **Approval-nya dekoratif untuk urusan uang.** `Payment::scopeIncome()` menyaring
   `status = 'paid'`, dan `store()` sudah menulis `'paid'`. `approve()` menulis
   `'paid'` lagi — no-op. Jadi `paidNet()`, Laporan Pemasukan, dan Target Marketing
   **sudah menghitung uang yang belum diverifikasi siapa pun**. Ini yang terdalam.
2. **Nominalnya tak dilihat.** Cabangnya menguji `payment_type`, bukan jumlah. DP
   Rp 500.000 atas order Rp 5.000.000 yang tak sengaja diketik bertipe "lunas" akan
   melunasi ordernya. (Ini pasangan dari catatan lama "`isLunas()` bohong untuk DP".)
3. **`reject()` menyapu buta.** `PaymentBookController:344-346` menurunkan order ke
   `'pending'` tanpa syarat. Order yang sudah benar-benar lunas dari pembayaran
   **lain** ikut jatuh ke `pending` hanya karena satu pembayaran tambahan ditolak.

### Fungsinya apa

`tb_orders.status` menjawab "sudah dibayar penuh atau belum". Pembacanya nyata dan banyak:

- `FinancialReportService::piutang()` — `where('status', '!=', 'lunas')`
- `FinancialReportService::orderSelesai()` — `where('status', 'lunas')` (layar "Order Lunas", PDF, CSV)
- `Title::isPaidOff()` → gerbang kelayakan arsip
- Gerbang pembatalan order, dashboard marketing, dan target komisi

Jadi bug ini memindahkan order antar laporan uang sebelum uangnya diverifikasi.

### Rencananya

Satu sumber kebenaran, dipanggil dari setiap titik yang bisa mengubah gambaran uang:

```php
// app/Models/Order.php
public function recalcStatus(): void
{
    if ($this->isCancelled()) {
        return; // dibatalkan tidak dihitung ulang
    }
    $cost = (int) optional($this->details)->cost_amount;
    $this->update(['status' => $this->paidNet() >= $cost && $cost > 0 ? 'lunas' : 'pending']);
}
```

Lalu:

- `store()` — **buang** cabang `payment_type === 'lunas'`. Pembayaran baru dibuat
  berstatus **`pending`**, bukan `paid`. Panggil `recalcStatus()`.
- `approve()` — di sinilah `status = 'paid'` ditulis untuk pertama kalinya, lalu
  `recalcStatus()`.
- `reject()` — ganti `update(['status' => 'pending'])` menjadi `recalcStatus()`.
- `update()` (edit nominal pembayaran) dan `destroy()` — `recalcStatus()`.

**Peringatan migrasi — ini bagian yang paling mudah merusak.** Mengubah pembayaran
baru menjadi `pending` mengubah arti kolom `tb_payments.status` untuk data yang sudah
ada. Baris lama berstatus `paid` yang approval-nya masih `pending` akan tetap terhitung
sebagai pemasukan (benar, karena begitulah selama ini dibaca), tapi baris baru tidak.
Perlu keputusan sadar: **backfill** (`paid` + approval `pending` → turunkan ke
`pending`) akan **menurunkan angka pemasukan historis**. Itu bukan keputusan teknis,
itu keputusan pemilik. Harus ditanyakan sebelum ditulis, bukan sesudah.

Backfill wajib memakai `DB::table()`, bukan model — pelajaran yang sudah tiga kali
kena di repo ini.

> **HASIL 2026-08-22: backfill TIDAK diperlukan — diverifikasi ke data, bukan
> diasumsikan.** Di `avidpedi_simapa128`: 220 pembayaran `paid` semuanya ber-approval
> `approved`; **nol** pembayaran `paid` dengan approval `pending`. Satu-satunya baris
> `paid` tanpa approval adalah **refund** (id 190), yang memang lahir tanpa
> PaymentApproval by design. Total pemasukan "sekarang" dan "kalau hanya yang
> approved" identik: Rp 207.850.000. Jadi tak ada angka historis yang bergerak, dan
> keputusan pemilik yang dikhawatirkan di atas ternyata tak punya isi. Tak ada
> migrasi backfill yang ditulis.

### Seharusnya

Status uang sebuah order adalah **fungsi murni** dari pembayaran yang **disetujui**
dibanding `cost_amount`-nya. Tak ada satu pun controller yang menulis `'lunas'` secara
harfiah; semuanya lewat `recalcStatus()`. Menolak sebuah pembayaran menghitung ulang,
bukan menebak.

---

## A4 — `lunas` tak dihitung ulang saat biaya order diedit

### Belum apa

`OrderBookController.php:472-481` dan `OrderJournalController.php:337` memperbarui
`cost_amount` dan tak menyentuh `$order->status`:

```php
$order->details()->update([
    ...
    'cost_amount' => $request->cost_amount,
]);
```

Dua arah gagalnya:

- Order sudah `lunas`, biayanya **dinaikkan** → tetap `lunas` padahal sekarang kurang
  bayar. Selisihnya tak pernah muncul di Piutang.
- Order `pending` yang uangnya sudah cukup, biayanya **diturunkan** → tetap `pending`,
  tak pernah masuk laporan "Order Lunas".

### Fungsinya apa

Sama dengan A3 — ini bug yang sama dilihat dari sisi lain: gambaran uang berubah, tapi
kesimpulannya tidak dihitung ulang.

### Rencananya

Panggil `recalcStatus()` yang sama, di dalam transaksi yang sama dengan update detail.
Kalau A3 dikerjakan lewat satu fungsi seperti di atas, A4 tinggal dua baris.

Tes yang mengunci: order lunas → naikkan biaya → assert `status === 'pending'` dan
order itu **muncul di Piutang**. Assert pada laporannya, bukan cuma pada kolomnya —
kolom yang benar tapi laporan yang tak berubah adalah kegagalan yang pernah terjadi
di sini.

### Seharusnya

Harga berubah → status uang dihitung ulang, seketika, di transaksi yang sama.

---

## A10 — nilai status tersebar sebagai literal

### Belum apa

`Invoice::STATUSES` sudah ada. `Order::STATUSES` dan `Order::FULFILLMENTS` lahir di
pekerjaan kemarin. Yang tertinggal: **`Payment` tak punya konstanta apa pun.** Nilai
`'paid'`, `'rejected'`, `'pending'`, dan tipe `'dp'`/`'lunas'`/`'pelunasan'`/`'refund'`
tersebar sebagai teks di controller, service, blade, dan tes.

### Fungsinya apa

Konstanta = satu tempat yang menyatakan nilai apa saja yang sah, dipakai bersama oleh
aturan validasi dan tes. Tanpa itu, salah ketik `'refunded'` lolos diam-diam sampai
ada yang membaca laporan.

### Rencananya

```php
// app/Models/Payment.php
public const STATUSES = ['pending', 'paid', 'rejected'];
public const TYPES    = ['dp', 'lunas', 'pelunasan', 'refund'];
```

Lalu ganti literal di aturan `in:` pada validasi, dan di controller.

**Kerjakan menempel pada A3**, bukan sebagai tugas terpisah — keduanya menyentuh
berkas yang sama, dan `'pending'` di daftar itu justru nilai yang A3 perkenalkan.
Sebagai pekerjaan sendiri, ini cuma kebersihan tanpa perubahan perilaku.

### Seharusnya

Tak ada nilai status yang ditulis harfiah di luar model pemiliknya.

---

## Sisa kebocoran: tanggal terbit bocor ke kolom "Tanggal Lunas"

### Belum apa

`FinancialReportService::orderSelesai()` (baris 72-97) sudah **tidak** membaca
`completed_at` — itu sengaja dicabut supaya tanggal pekerjaan tak masuk laporan uang.
Tapi pencabutannya tidak tuntas, dan komentarnya di kode sudah mengakui ini:

```php
$o->tanggal_lunas = $o->updated_at;
```

`OrderFulfillmentService::apply()` memanggil `$order->save()` tepat pada saat naskah
mencapai `publish`/`terbit`. `Order` memakai timestamps, jadi `updated_at` tercap
momen terbit. Kolom "Tanggal Lunas" karenanya menampilkan **tanggal terbit** untuk
order yang baru terbit — dan `orderByDesc('updated_at')` menaikkannya ke puncak laporan.

Sebelum pekerjaan kemarin tak ada yang menyentuh order saat terbit, jadi kolom itu tak
pernah bergerak. Ini efek samping yang lahir dari perbaikan A2, diketahui dan diterima
saat itu, bukan terlewat.

### Fungsinya apa

"Tanggal Lunas" mestinya menjawab: kapan order ini selesai dibayar. Itu tanggal uang.

### Rencananya

Turunkan dari pembayaran, bukan dari jejak penyimpanan baris:

```php
$o->tanggal_lunas = $o->payments()->income()->max('paid_at');
```

Ini **bergantung pada A3** — "income" baru punya arti yang benar setelah `paid` berarti
"disetujui". Dan bergantung pada **A13**, karena `max('paid_at')` yang dibaca sebagai
string akan berperilaku aneh di perbandingan PHP. Kerjakan setelah keduanya.

Urutan laporan ikut pindah ke tanggal itu, bukan `updated_at`.

Tes `tanggal_lunas_tidak_lagi_membaca_completed_at` sengaja dinamai sempit karena
hanya memagari pembacaan eksplisitnya. Setelah item ini selesai, tesnya boleh diperluas
dan namanya diperbaiki.

> **HASIL 2026-08-22: selesai, dan tesnya sudah diperluas.** Ia kini bernama
> `tanggal_lunas_berasal_dari_pembayaran_bukan_dari_tanggal_terbit` dan memagari TIGA
> sumber palsu sekaligus: bukan `completed_at`, bukan `updated_at`, harus `paid_at`
> pembayaran yang disetujui. Fixture-nya diberi jarak waktu nyata (uang masuk tiga
> bulan sebelum naskah terbit) — tanpa itu ketiga tanggal jatuh di hari yang sama dan
> assertion-nya lulus semu, jebakan yang tes lama sudah antisipasi untuk `completed_at`.
>
> Urutan laporan ikut pindah ke `withMax(payments income, paid_at)`, jadi order yang
> baru terbit tak lagi naik ke puncak laporan pelunasan.

### Seharusnya

Tanggal Lunas = `paid_at` pembayaran terakhir yang melunasi. Tak ada kolom uang yang
diturunkan dari `updated_at`.

---

## A5 — invoice `jatuh_tempo` masih manual

### Belum apa

`Invoice::isOverdue()` ada dan benar (`app/Models/Invoice.php:61`):

```php
return $this->due_at !== null
    && $this->due_at->isPast()
    && !in_array($this->status, ['lunas', 'dibatalkan', 'refund']);
```

**Tak ada satu pun pemanggilnya.** Diverifikasi dengan grep ke seluruh `app/` dan
`resources/`. Status `jatuh_tempo` hanya bisa muncul kalau ada orang menyetelnya
tangan lewat `InvoiceController::updateStatus()`.

Scheduler di `app/Console/Kernel.php` sudah menjalankan `naskah:check-overdue` tiap
pagi 07:00 — jadi keterlambatan **naskah** ketahuan sendiri. Tak ada padanannya untuk
invoice.

### Fungsinya apa

`jatuh_tempo` adalah penanda tagihan yang sudah lewat tempo — bahan kerja penagihan.
Tanpa itu, tagihan lewat tempo hanya ketahuan kalau ada yang ingat membuka daftar
invoice dan membandingkan tanggal sendiri.

### Rencananya

Command + jadwal, meniru persis pola `naskah:check-overdue` yang sudah terbukti:

```php
// app/Console/Kernel.php
$schedule->command('invoice:check-overdue')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/invoice-overdue.log'));
```

Commandnya:
1. Ambil invoice berstatus `diterbitkan` yang `due_at`-nya sudah lewat.
2. Ubah ke `jatuh_tempo`.
3. **Tulis `InvoiceLog`** — setiap perubahan status invoice lain sudah punya jejak;
   yang otomatis tak boleh jadi pengecualian, atau riwayatnya bolong justru di
   perubahan yang tak ada saksinya.
4. Notifikasi ke **pemilik order (marketing)**, bukan ke klien. Ini alat kerja internal;
   mengirim email otomatis ke klien adalah keputusan bisnis yang belum diambil siapa pun.

Tak butuh migrasi — kolom dan nilai statusnya sudah ada.

Catatan pengoperasian: produksi hanya punya **satu** cron (`schedule:run`), dan baris
di atas ikut di dalamnya. Tak ada yang perlu ditambahkan di server.

### Seharusnya

Status jatuh tempo terisi sendiri tiap pagi, dengan jejak di InvoiceLog seperti
perubahan status invoice lainnya, dan orang yang bertanggung jawab menagih diberi tahu.

---

# KELOMPOK TERBIT

A1 sudah tutup. Dua item tersisa, dan keduanya berbeda sifat: A8 sebaiknya **ditutup
sebagai keputusan** tanpa membangun apa pun, sementara A9 adalah pekerjaan terbesar
yang tersisa di seluruh daftar ini.

---

## A8 — judul tak punya penanda terbit

### Belum apa

`tb_titles.status` hanya mengenal `draft`, `ditolak`, `disetujui`
(`app/Models/Title.php:166`). Judul yang naskahnya sudah terbit tetap berstatus
`disetujui`. Tak ada nilai `terbit`.

### Fungsinya apa — dan di sinilah temuannya

`Title::status` bukan status produksi. Ia status **tata kelola**: usulan judul ini
diterima, ditolak, atau masih draft. Itu pertanyaan yang berbeda dari "naskahnya sudah
terbit belum".

Dan pertanyaan kedua itu **sudah terjawab**, lewat turunan:

```php
Title::manuscriptStatus()   // tahap paling belakang di antara order yang tidak ditarik
Title::manuscriptIsFinal()  // true bila tahap itu terbit/publish
```

Keduanya sudah dipakai Arsip Judul dan gerbang kelayakan arsip, dan keduanya sudah
tahu cara mengabaikan order yang ditarik karena refund.

### Rencananya — rekomendasi: **jangan tambah kolom**

Menambah `status = 'terbit'` berarti menyimpan turunan sebagai kolom. Kolom turunan
bisa basi, dan di sistem ini pasti akan basi: satu judul punya banyak order, order bisa
ditarik, tahap bisa dikoreksi mundur oleh superadmin. Setiap satu dari peristiwa itu
harus ingat memperbarui kolomnya — dan yang lupa tak akan bersuara.

Turunan tidak bisa basi. Ia sudah benar hari ini.

Yang benar-benar kurang bukan datanya, melainkan **tampilannya**: Direktori Judul tak
menunjukkan bahwa naskah sebuah judul sudah terbit. Jadi pekerjaannya:

- Lencana "Terbit" di Direktori Judul, dibaca dari `manuscriptStatusLabel()`
- Filter "sudah terbit / belum" di daftar itu
- Nol migrasi, nol kolom baru

Satu hal yang perlu diukur lebih dulu: `manuscriptStatus()` menghitung lewat koleksi,
bukan SQL. Untuk satu halaman daftar berisi ratusan judul, itu berarti banyak query.
Kalau lambat, jawabannya scope SQL untuk **penyaringan**, tetap tanpa kolom simpanan.

### Seharusnya

A8 ditutup di dokumen audit sebagai **keputusan yang diambil** ("tidak ditambahkan,
sengaja — sudah ada turunannya"), bukan sebagai fitur yang menunggu. Yang masuk daftar
kerja hanya lencana dan filternya.

> **HASIL 2026-08-22: A8 DITUTUP. Cakupannya ternyata lebih kecil lagi.**
> Lencana "Terbit" **sudah ada** di `resources/views/titles/index.blade.php:61`
> (hijau saat tahap final), dan kekhawatiran N+1 juga sudah tak berlaku —
> `manuscriptStatus()` membaca koleksi yang sudah di-eager load, dengan komentar yang
> menjelaskan kenapa. Yang benar-benar kurang cuma **filternya**, dan itu sudah
> dibangun (`?terbit=sudah|belum`).
>
> Filternya disaring di **koleksi, bukan SQL**, dan itu disengaja: menulis ulang
> "sudah terbit" sebagai predikat SQL akan melahirkan definisi kedua yang bisa
> berselisih dengan turunannya — persis alasan A8 memutuskan tidak menyimpannya
> sebagai kolom. Nol migrasi, nol kolom baru, sesuai rekomendasi.

---

## A9 — `revisi` terletak sebelum `submit`, dan penolakan tak punya tempat

**Item terbesar dan paling berisiko yang tersisa. Jangan langsung dikerjakan.**

### Belum apa

```php
const ARTICLE_STAGES = [
    'menunggu_proses', 'pembuatan', 'editing',
    'revisi', 'submit', 'loa', 'publish',
];
```

Alur sebenarnya di jurnal: naskah **di-submit** dulu → reviewer meminta **revisi** →
submit ulang → **LoA** → publish. Urutan di kode adalah kebalikannya.

Dan submission bisa **ditolak** reviewer. Tak ada tahap untuk itu sama sekali.

### Fungsinya apa

Urutan ini bukan sekadar label untuk ditampilkan. Ia struktur yang dipakai:

- `TitleProgressService::advance()` maju **satu langkah lewat urutan ini**
- `Title::manuscriptStatus()` mencari tahap paling belakang berdasarkan **posisi indeks**
- Meja Kerja, SLA, dan dashboard produksi menghitung dari posisi yang sama

Jadi salah urutan = salah di semua tempat itu sekaligus.

### Akibatnya hari ini

- Naskah yang diminta revisi setelah submit tak bisa maju lewat alur normal. PJ harus
  memakai **"koreksi"** — yang superadmin-only dan wajib beralasan. Alat untuk
  memperbaiki kesalahan dipakai sebagai alur kerja harian.
- Penolakan tak punya tempat, jadi dicatat di catatan bebas dan tak bisa dihitung,
  disaring, atau dilaporkan.
- `revisi` yang terletak sebelum `submit` juga berarti tiap naskah "melewati" revisi
  dalam perjalanan normalnya — padahal revisi mestinya perkecualian, bukan tahap wajib.

### Rencananya — dua opsi, dan keduanya belum matang

**Opsi 1 — pindahkan dan tambah (murah).** `revisi` digeser ke antara `submit` dan
`loa`; tambah `ditolak`. Migrasi datanya kecil.
Kelemahannya: alurnya tetap linear, jadi `submit → revisi → loa` memaksa **setiap**
naskah melewati revisi. Bug yang sama, cuma pindah tempat.

**Opsi 2 — revisi bukan tahap, melainkan perulangan (benar, tapi besar).** Cabut
`revisi` dari daftar tahap; jadikan penanda pada tahap `submit` (`revision_round`,
angka). Tambah `ditolak` sebagai **akhir alternatif** yang sejajar `publish` — masuk
arsip seperti "ditarik", bukan menggantung.

Rekomendasi condong ke **opsi 2**, tapi dengan mata terbuka soal biayanya:

- Butuh migrasi data untuk baris yang **sekarang** berstatus `revisi` — dan itu berarti
  menerka ulang: apakah naskah itu sudah pernah di-submit atau belum? Datanya mungkin
  tak menyimpan jawabannya. Perlu dilihat dulu berapa baris yang terdampak.
- Menyentuh `advance()`, `nextStage()`, `isFinal()`, kelayakan arsip, dan seluruh
  perhitungan indeks di dashboard.
- Menyentuh `JurnalSubmissionService` — **milik sesi lain**, baru selesai 2026-08-22.
  Harus dikoordinasikan, bukan diubah sepihak.
- `ditolak` sebagai akhir yang sah menimbulkan pertanyaan turunan yang belum dijawab:
  order-nya jadi apa? `fulfillment_status = 'selesai'` jelas salah. Butuh nilai baru,
  atau `ditarik` dipakai ulang dengan arti berbeda — dan memakai ulang nilai untuk arti
  berbeda adalah persis kesalahan yang K3 dibuat untuk mencegah.

### Seharusnya

Revisi tercatat sebagai **putaran pada tahap submit**, bukan tahap tersendiri.
Penolakan adalah akhir yang sah, masuk arsip, dan bisa dihitung. Naskah yang lancar
tak pernah menyentuh keduanya.

### Langkah berikutnya untuk item ini

**Brainstorming sendiri, bukan langsung rencana.** Yang harus dijawab lebih dulu:

1. Berapa baris `tb_title_progress` yang sekarang berstatus `revisi`, dan apakah
   riwayatnya cukup untuk menerka mereka sudah pernah submit atau belum?
2. Apakah "ditolak" berarti naskahnya mati, atau boleh disubmit ke jurnal lain?
   Jawabannya menentukan apakah ia akhir atau percabangan.
3. Order dari naskah yang ditolak masuk keadaan apa, dan uangnya bagaimana?

Pertanyaan ketiga menyeberang ke Kelompok Uang. Itu alasan tambahan mengerjakan
Kelompok Uang lebih dulu.

---

## Yang TIDAK ada di daftar ini

Supaya tak dikira terlewat:

- **A1, A2, A6, A7, A11, A12** — selesai. A2/A6/A7/A11/A12 di pekerjaan
  2026-08-20..21; A1 oleh sesi lain 2026-08-22 (`b21f761`).
- **Pemisahan `fulfillment_status`** — sengaja, dan tak akan digabung kembali ke
  `status`. Lihat K3 di dokumen audit.
- **Pembayaran memblokir laju naskah** — sengaja **tidak** dilakukan (K1). Peringatan
  muncul di Arsip Judul, bukan sebagai gerbang di papan naskah.
