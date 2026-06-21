# Spec B — Notification System (in-app)

- **Tanggal:** 2026-06-21
- **Status:** Disetujui (siap masuk implementation plan)
- **Scope:** Notifikasi in-app berbasis event aksi (approval pembayaran, tagihan, proses naskah), tampil di lonceng navbar + halaman daftar.
- **Di luar scope (sengaja):** channel email, pengingat deadline terjadwal (spec terpisah), push realtime/websocket, preferensi/mute per-user.

---

## 1. Latar Belakang

Permintaan awal: "tambahkan notifikasi semua nya baik approval, proses naskah, dll." `User` sudah memakai trait `Notifiable`, tetapi tabel `notifications` Laravel belum ada (belum ada migrasi). Titik aksi sudah terpusat: `PaymentBookController` (store/approve/reject), `TagihanController` (store/approve/reject/update), dan `TitleProgressService::changeStatus`. Spec ini memakai **notifikasi database bawaan Laravel** (pendekatan A) dengan service `Notifier` sebagai pusat logika.

## 2. Tujuan & Kriteria Sukses

1. Setiap event aksi yang relevan membuat notifikasi in-app untuk penerima yang tepat (lihat matriks §3.3).
2. Lonceng di navbar menampilkan jumlah belum dibaca + daftar ringkas; ada halaman daftar lengkap dengan "tandai semua dibaca".
3. Setiap user hanya melihat notifikasi miliknya (ter-scope otomatis oleh relasi `notifiable`).
4. Kegagalan kirim notifikasi tidak pernah membatalkan/menggagalkan aksi inti (approval, dsb.).
5. Semua perilaku tertutup test; suite tetap hijau.

---

## 3. Desain (Pendekatan A: notifikasi database Laravel + service `Notifier`)

### 3.1 Skema data

`php artisan notifications:table` menghasilkan migrasi tabel `notifications` standar: `id` (UUID), `type`, `notifiable_type`, `notifiable_id`, `data` (JSON/text), `read_at` (nullable), timestamps.

Bentuk payload `data`:

```json
{ "category": "payment|tagihan|naskah", "title": "...", "message": "...", "url": "/...", "icon": "feather-name" }
```

- `category` → menentukan ikon & warna di UI.
- `url` → tautan dalam (deep link) ke halaman terkait.
- read/unread dilacak oleh kolom `read_at` (mekanisme bawaan Laravel: `markAsRead`, `unreadNotifications`).

### 3.2 `DatabaseNotification` (amplop)

`app/Notifications/DatabaseNotification.php` — satu kelas untuk semua event:

```php
class DatabaseNotification extends \Illuminate\Notifications\Notification
{
    public function __construct(private array $payload) {}
    public function via(object $notifiable): array { return ['database']; }
    public function toArray(object $notifiable): array { return $this->payload; }
}
```

Sinkron (tanpa queue) — channel database hanya insert satu baris per penerima.

### 3.3 `Notifier` service (pusat logika) — `app/Services/Notifier.php`

Satu method publik per event. Tiap method: resolusi penerima (kecuali aktor) → bangun payload → kirim. Method privat `send($recipients, array $payload)` membungkus `Notification::send(...)` dalam `try/catch` + `Log::warning` (kegagalan tidak melempar).

| Method | Dipicu dari | Penerima |
|---|---|---|
| `paymentSubmitted(Payment, User $actor)` | PaymentBookController::store | role `manager` + `superadmin` |
| `paymentApproved(Payment, User $actor)` | PaymentBookController::approve | pemilik order (`payment.order.user`) |
| `paymentRejected(Payment, User $actor, ?string $note)` | PaymentBookController::reject | pemilik order |
| `tagihanSubmitted(Tagihan, User $actor)` | TagihanController::store & update (resubmit) | role `superadmin` |
| `tagihanApproved(Tagihan, User $actor)` | TagihanController::approve | pembuat (`tagihan.creator`, `created_by`) |
| `tagihanRejected(Tagihan, User $actor)` | TagihanController::reject | pembuat (sertakan `reject_note` di message) |
| `naskahStageChanged(TitleProgress, User $actor, string $from, string $to)` | TitleProgressService::changeStatus | pemilik order (`progress.orderDetail.order.user`) |
| `naskahNeedsReview(TitleProgress, User $actor)` | TitleProgressService::changeStatus (saat `needs_review` di-set) | role `manager` + `superadmin` |

**Resolusi penerima:** lookup role via Spatie `User::role([...])->where('is_active', true)->get()`; selalu buang aktor (`->reject(fn ($u) => $u->id === $actor->id)`) dan buang duplikat. Bila penerima kosong, tidak mengirim apa pun (no-op).

**Contoh payload** (paymentApproved): `category=payment`, `title="Pembayaran disetujui"`, `message="INV {no} • Rp {amount}"`, `url=route('payment.index')` (tidak ada route detail pembayaran tunggal, jadi mengarah ke daftar Pembayaran Disetujui), `icon="credit-card"`. Naskah: `url=route('order.indexJudul.progress', $progress->order_detail_id)`, `icon="book-open"`. Tagihan: `url=route('tagihan.show', $tagihan->id)`, `icon="file-text"`.

### 3.4 Wiring titik picu

Panggilan `Notifier` satu baris ditempatkan **setelah** blok `DB::transaction(...)` commit di tiap titik (sehingga notifikasi tidak ikut di-rollback). `TitleProgressService::changeStatus` memanggil `naskahStageChanged` (dan `naskahNeedsReview` bila menandai review) setelah perubahan tersimpan. Aktor diambil dari user yang sedang login / parameter `$user` yang sudah diterima method tersebut.

### 3.5 UI

- **Lonceng navbar** — markup di partial `resources/views/layouts/partials/notifications.blade.php`, di-`@include` pada `resources/views/layouts/header.blade.php` tepat sebelum dropdown profil: ikon lonceng + badge jumlah belum dibaca; dropdown berisi ~7 notifikasi terbaru (ikon per `category`, `title`, `message` dipotong, waktu `diffForHumans()`, tautan), footer "Lihat semua".
- **Halaman daftar** `/notifications` — `resources/views/notifications/index.blade.php`: feed kronologis (belum dibaca di-highlight), paginasi Laravel, tombol "Tandai semua dibaca".
- **Data navbar** — View Composer (di `AppServiceProvider::boot`) yang membagikan `$navUnread` (count) + `$navRecent` (7 terbaru) ke partial header untuk user terautentikasi; tidak perlu plumbing per-controller.

### 3.6 Routes + controller

`app/Http/Controllers/NotificationController.php`, dalam grup `auth` (semua role — tiap user punya lonceng):

| Route | Method | Aksi |
|---|---|---|
| `GET /notifications` (`notifications.index`) | index | paginasi notifikasi milik `auth user` |
| `POST /notifications/{id}/read` (`notifications.read`) | read | tandai satu dibaca → redirect ke `data.url` (fallback `back()`) |
| `POST /notifications/read-all` (`notifications.readAll`) | readAll | `auth user`->unreadNotifications->markAsRead → `back()` |

`read`/`readAll` hanya beroperasi pada notifikasi milik `auth()->user()` (scoping bawaan relasi `notifications`).

### 3.7 Penanganan error

Notifikasi dikirim **setelah commit** + dibungkus `try/catch` + `Log::warning` di dalam `Notifier::send`. Maka kegagalan kirim notifikasi tidak pernah menggagalkan approval/proses. Channel database sinkron; tidak bergantung pada worker queue.

---

## 4. Komponen yang Disentuh / Dibuat

- **Baru:** migrasi `notifications` (artisan), `app/Notifications/DatabaseNotification.php`, `app/Services/Notifier.php`, `app/Http/Controllers/NotificationController.php`, `resources/views/notifications/index.blade.php`, partial lonceng `resources/views/layouts/partials/notifications.blade.php` (di-include oleh header), View Composer di `AppServiceProvider`.
- **Dimodifikasi:** `routes/web.php` (3 route), `resources/views/layouts/header.blade.php` (lonceng), `PaymentBookController` (store/approve/reject), `TagihanController` (store/approve/reject/update), `app/Services/TitleProgressService.php` (changeStatus).

## 5. Rencana Test

- **Unit `tests/Unit/NotifierTest.php`** (`Notification::fake()`): tiap method mengirim ke penerima benar (`assertSentTo`), mengecualikan aktor, payload memuat `category/title/url`; penerima kosong → `assertNothingSent`.
- **Feature `tests/Feature/NotificationUiTest.php`**: badge jumlah belum dibaca; index hanya menampilkan milik sendiri (bukan user lain); `read` menandai dibaca + redirect ke url; `readAll` mengosongkan belum dibaca.
- **Feature hook (satu per domain)**: approve pembayaran → notifikasi tersimpan untuk pemilik; approve tagihan → untuk pembuat; majukan tahap naskah → untuk pemilik.

Suite dijalankan terhadap `avidpedi_simapa_test` via `.env.testing` (`APP_ENV=testing`); migrasi `notifications` ikut ter-migrate oleh `RefreshDatabase`.

## 6. Asumsi & Risiko

- Approval tagihan = `superadmin` saja (sesuai controller saat ini) → `tagihanSubmitted` menyasar superadmin. `paymentSubmitted` menyasar manager + superadmin (sesuai gating route approve/reject pembayaran).
- Volume notifikasi per user diasumsikan wajar; lonceng memuat 7 terbaru + count (query ringan per page-load via View Composer).
- Migrasi `notifications` harus dijalankan di server produksi saat rilis (di luar scope test, bagian deployment).
- Notifikasi `naskahStageChanged` dikirim pada setiap kemajuan tahap; pesan khusus untuk tahap final (`terbit`/`publish`).
