# Keamanan — daftar periksa deployment

Ditulis setelah audit keamanan 2026-07-24. Bagian yang bisa ditegakkan lewat
kode sudah ditegakkan (lihat "Sudah ditangani di kode"); berkas ini menampung
sisanya, yaitu hal-hal yang hanya bisa dipastikan di **server**, bukan di repo.

## Wajib dicek di server produksi

### 1. DocumentRoot harus menunjuk ke `public/`

Kalau DocumentRoot mengarah ke akar proyek, `.env`, dump `.sql`, dan seluruh
isi `storage/` bisa diunduh lewat URL. Ada `.htaccess` di akar proyek sebagai
lapisan kedua, tapi itu **bukan pengganti** DocumentRoot yang benar.

### 2. Dependensi tanpa dev-package — OTOMATIS via deploy-tools

Server **tidak punya akses terminal**, jadi paket dibangun di LOKAL sebelum
di-zip (vendor ikut di zip; composer tak perlu jalan di server). Kini seluruh
proses ini satu perintah lewat `simapa-avid/deploy-tools/` (lihat README-nya):

```powershell
.\deploy-tools\build.cmd --zip
```

Skrip itu sudah mencakup: ekspor kode dev, `composer install --no-dev`,
`php artisan package:discover` (bangun manifes — mencegah 500), salin aset,
taruh `.env` produksi, dan mirror ke `simapa-deploy`. Jadi langkah manual di
bawah **tidak perlu lagi** — didokumentasikan hanya untuk pemahaman:

```bash
# (ekuivalen manual dari yang dilakukan build.cmd)
composer install --no-dev --optimize-autoloader --no-scripts
rm bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover
```

Yang tercabut: `phpunit`, `spatie/laravel-ignition`, `mockery`, `fakerphp`,
`laravel/sail`, `nunomaduro/collision`. Ignition + `APP_DEBUG=true` adalah jalur
eksekusi kode jarak jauh yang terdokumentasi; PHPUnit membawa CVE-2026-24765.

`psy/psysh` dan `nunomaduro/termwind` sengaja TETAP ADA — keduanya dependensi
produksi (psysh ditarik `laravel/tinker` yang duduk di `require`, bukan
`require-dev`), bukan sisa yang terlewat.

> **WAJIB menyusul `--no-dev`:** bangun ulang manifes discovery.
> `bootstrap/cache/packages.php` mencatat service provider Ignition/Sail/Collision;
> kalau paketnya hilang sementara manifesnya tidak diperbarui, Laravel memuat
> kelas yang sudah tidak ada → **500 di semua halaman**.
>
> ```bash
> rm bootstrap/cache/packages.php bootstrap/cache/services.php
> php artisan package:discover
> ```

Verifikasi (dijalankan di lokal, sebelum zip):

```bash
ls vendor/phpunit vendor/spatie/laravel-ignition        # harus "No such file"
grep -c Ignition bootstrap/cache/packages.php           # harus 0
php artisan route:list | wc -l                          # harus memuat semua route
```

### 3. Setelan `.env` produksi

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://simapa.avidpedia.com
SESSION_SECURE_COOKIE=true
```

- `APP_DEBUG=true` menampilkan halaman galat yang memuat isi `.env`.
- `APP_URL` berskema `https` otomatis memaksa seluruh URL yang dibangun
  aplikasi ikut https (lihat `AppServiceProvider::boot`).
- `SESSION_SECURE_COOKIE=true` mencegah cookie sesi terkirim lewat HTTP polos.
- `APP_ENV` selain `local` juga mengaktifkan `TrustHosts`, yang menutup
  peracunan tautan reset password lewat header `Host`.

### 4. Hapus `public/error_log`

```bash
rm -f public/error_log
```

Berkas ini bisa diunduh siapa saja dan membocorkan path absolut server
(`/home/avidpedi/...`) beserta struktur `vendor/`. `.htaccess` sudah menolak
aksesnya, tapi lebih baik tidak ada sama sekali.

### 5. Paksa HTTPS di webserver

Redirect HTTP → HTTPS di level Apache/cPanel. Aplikasi sudah membangun URL
https begitu `APP_URL` https, tapi permintaan HTTP pertama tetap harus dialihkan.

## Rahasia

`.env.bak` dan `.env.testing` **tidak pernah masuk riwayat git** (sudah
diperiksa: `git log --all -- <berkas>` kosong, hanya `.env.example` yang
terlacak). Jadi tidak ada rotasi darurat yang wajib dilakukan.

`.gitignore` kini menutup `.env.*` (kecuali `.env.example`), `*.sql`, dan
`error_log`. Tetap biasakan `git add` dengan path eksplisit, jangan `-A`.

Kalau suatu saat `.env` benar-benar bocor, yang harus dirotasi:
`APP_KEY` (bocornya berarti cookie sesi bisa dipalsukan untuk user mana pun),
password basis data, password SMTP, dan refresh token Google Drive.

## Utang yang belum dibayar

### Laravel 10 sudah habis masa dukungan keamanan

`laravel/framework v10.50.0`. Laravel 10 berhenti menerima perbaikan keamanan
pada **4 Februari 2025** — CVE framework yang ditemukan setelah itu tidak akan
di-backport. `composer audit` melaporkan 40 advisory di 16 paket saat audit,
4 di antaranya *high*.

Upgrade ke Laravel 12 adalah proyek tersendiri dan **sengaja tidak dikerjakan**
dalam penambalan ini. Sampai itu terjadi, ini adalah risiko terbesar yang
tersisa di aplikasi.

### CSP masih memakai `unsafe-inline`

`SecurityHeaders` mengirim CSP, tapi `script-src` masih mengizinkan
`'unsafe-inline'` karena hampir semua halaman menaruh `<script>` inline lewat
`@push('custom-scripts')`. Artinya CSP di sini **bukan** penangkal XSS —
penangkalnya adalah penyaringan masukan di `App\Support\HtmlSanitizer`. Yang
benar-benar ditegakkan CSP: `frame-ancestors`, `base-uri`, `object-src`, dan
`form-action`.

Menghapus `unsafe-inline` butuh memindahkan skrip inline ke berkas terpisah
plus nonce per permintaan.

### Password sementara masih dikirim polos lewat surel

`WelcomeEmail` mengirim password yang dibuatkan admin dalam bentuk teks biasa,
dan surel itu mengendap permanen di kotak masuk. Dampaknya kini jauh berkurang
karena `force_password_change` sudah benar-benar ditegakkan (`ForcePasswordChange`),
sehingga password tersebut hanya berlaku sampai login pertama. Perbaikan
tuntasnya: kirim tautan set-password bertanda tangan, bukan passwordnya.

### `ManagementUserController::profileImage()`

Metode kembaran dari `ProfileController::profileImage()` yang **tidak punya
route**, jadi tidak bisa dijangkau. Dibiarkan apa adanya, tapi jangan
dipasangkan route tanpa menyalin dulu pemeriksaan `UserProfile` yang ada di
versi `ProfileController`.

## Sudah ditangani di kode

| Temuan | Perbaikan | Test |
|---|---|---|
| Endpoint `POST /register` masih hidup | Route, controller, dan view dicabut | `Auth/RegistrationTest` |
| `is_active` tak dicek saat login | Jadi syarat kredensial | `Auth/AuthenticationTest` |
| Role menumpuk saat edit user | `syncRoles` | `UserRoleSyncTest` |
| XSS tersimpan di pengumuman | Sanitizer allowlist | `Unit/HtmlSanitizerTest`, `AnnouncementAdminTest` |
| Tanpa header keamanan | Middleware `SecurityHeaders` | `SecurityHeadersTest` |
| Tanpa pembatas laju | `throttle:web`/`export`/`mail` | `RateLimitTest` |
| `force_password_change` tak ditegakkan | Middleware `ForcePasswordChange` | `ForcePasswordChangeTest` |
| Proxy Drive menerima ID apa pun | Wajib terdaftar sebagai foto profil | `ProfileImageProxyTest` |
| Rahasia tak diabaikan git | `.gitignore` + `.htaccess` | — |
| CORS `*`, robots.txt terbuka | Dipersempit | — |
