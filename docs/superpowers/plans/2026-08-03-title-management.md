# Pengelolaan Judul (Field Order · Edit · Nonaktif & Hapus) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menutup tiga kebocoran di data judul — dropdown order tidak lagi menyimpan string `KODE — Judul`, judul berstatus `disetujui` bisa diperbaiki, dan judul yang belum terpakai bisa dihapus (yang sudah terpakai bisa dinonaktifkan).

**Architecture:** Tiga lapis untuk field judul: label bersih (satu partial bersama menggantikan markup yang tersalin di empat view), nilai tak ambigu (`new:` prefix menggantikan tebakan `is_numeric()`), dan artisan command untuk membersihkan data yang sudah terlanjur kotor. Untuk siklus hidup judul: `tb_titles` mendapat `softDeletes()` + `deactivated_at`, dengan aturan hapus yang ketat (tidak ada order aktif / ISBN / arsip) dan "nonaktif" sebagai jalan keluar untuk judul yang sudah terpakai.

**Tech Stack:** Laravel 10, Eloquent SoftDeletes, Select2 4.x (`createTag`/`insertTag`/`templateResult`), Spatie Permission, Bootstrap 5 + DataTables, PHPUnit.

**Spec:** [`docs/superpowers/specs/2026-08-03-order-cancel-title-management-design.md`](../specs/2026-08-03-order-cancel-title-management-design.md) — Bagian 2a (§2), 2b (§3), 2c (§4) + urutan implementasi §8 langkah 4–7.

---

## Prasyarat

**Task 6 rencana ini bergantung pada Task 1 rencana [`2026-08-03-order-cancel-edit.md`](2026-08-03-order-cancel-edit.md)** (trait `SoftDeletes` di `OrderDetail`). Aturan "judul boleh dihapus bila tidak punya **order aktif**" (spec §4.2) mengandalkan global scope soft delete `OrderDetail` supaya judul yang jadi yatim akibat order dibatalkan otomatis memenuhi syarat hapus. Tanpa itu, `deleteBlockReason()` akan tetap menghitung order yang sudah dibatalkan.

Task 1–5 rencana ini **tidak** punya ketergantungan itu dan bisa dikerjakan paralel dengan rencana Order.

---

## Penyimpangan yang disengaja dari spec (baca sebelum mulai)

**Validasi panjang judul tidak dipindah ke Rule class.** Spec §2.2 hanya bilang "validasi di `store()`/`update()`". Karena prefix `new:` membuat aturan `max:255` yang ada memotong judul sah pada 251 karakter, aturan panjangnya dipindah ke helper `TitleService::titleNameFrom()` + penjagaan eksplisit di controller — bukan `app/Rules/` yang belum pernah dipakai codebase ini. Helper yang sama sekaligus menggantikan blok `is_numeric()` untuk cek duplikat yang sudah tersalin di `OrderBookController::store()` dan `OrderJournalController::store()`.

**Dropdown judul memakai class `title-select`, bukan `select2`.** [`public/assets/js/select2.js`](../../../public/assets/js/select2.js) menjalankan `$(".select2").select2()` untuk semua elemen ber-class itu. Bila partial memakai class `select2` **dan** menginisialisasi sendiri, Select2 ter-init dua kali dan konfigurasi `createTag`/`insertTag` yang kedua bisa terabaikan. Class terpisah membuat kepemilikan inisialisasi jelas.

---

## File Structure

| Berkas | Tanggung jawab |
|---|---|
| `database/migrations/2026_08_03_000003_add_lifecycle_fields_to_tb_titles.php` | `softDeletes()`, `deactivated_at`, `deactivated_by` |
| `app/Models/Title.php` | `SoftDeletes`, `isActive()`, `scopeActive()`, `isDeletable()`, `deleteBlockReason()`, `isEditable()` diperlebar |
| `resources/views/orders/partials/title-select.blade.php` | **Baru.** Dropdown judul bersama untuk 4 form order |
| `resources/views/orders/{book/create,journal/create,edit,journal/edit}.blade.php` | Memakai partial |
| `app/Services/TitleService.php` | `resolveForOrder()` berprefix, `titleNameFrom()`, `update()` + sinkron & log |
| `app/Http/Controllers/Pages/OrderBookController.php`, `OrderJournalController.php` | Validasi nama judul + dropdown `active()` |
| `app/Console/Commands/StripTitleCodePrefix.php` | **Baru.** `php artisan titles:strip-code-prefix [--apply]` |
| `app/Http/Controllers/Pages/TitleController.php` | Gerbang edit `disetujui`, `destroy()` baru, `deactivate()`, `activate()` |
| `routes/web.php`, `config/permissions.php`, `database/seeders/AccessMatrixSeeder.php` | Route + permission `title.deactivate` |
| `resources/views/titles/index.blade.php` | Kolom Aksi: Edit untuk `disetujui`, Hapus (dengan alasan), Nonaktifkan/Aktifkan, toggle |
| `tests/Feature/TitleSelectResolveTest.php`, `TitleEditApprovedTest.php`, `TitleLifecycleTest.php`, `StripCodePrefixCommandTest.php` | **Baru.** |

---

## Task 1: Skema & model siklus hidup judul

**Files:**
- Create: `database/migrations/2026_08_03_000003_add_lifecycle_fields_to_tb_titles.php`
- Modify: `app/Models/Title.php`
- Test: `tests/Feature/TitleLifecycleTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/TitleLifecycleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TitleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function makeTitle(User $creator, array $attrs = []): Title
    {
        return Title::create(array_merge([
            'title'       => 'Judul Uji',
            'code'        => 'JU',
            'jenis'       => 'buku',
            'tipe_naskah' => 'mandiri',
            'status'      => 'disetujui',
            'asal'        => 'order',
            'created_by'  => $creator->id,
        ], $attrs));
    }

    /** @test */
    public function kolom_siklus_hidup_judul_tersedia(): void
    {
        $this->assertTrue(Schema::hasColumns('tb_titles', ['deleted_at', 'deactivated_at', 'deactivated_by']));
    }

    /** @test */
    public function judul_memakai_soft_delete(): void
    {
        $title = $this->makeTitle($this->user('admin'));
        $title->delete();

        $this->assertSoftDeleted('tb_titles', ['id' => $title->id]);
        $this->assertNull(Title::find($title->id));
        $this->assertNotNull(Title::withTrashed()->find($title->id));
    }

    /** @test */
    public function scope_active_menyaring_judul_nonaktif(): void
    {
        $admin  = $this->user('admin');
        $aktif  = $this->makeTitle($admin, ['title' => 'Judul Aktif', 'code' => 'JA']);
        $mati   = $this->makeTitle($admin, ['title' => 'Judul Nonaktif', 'code' => 'JN']);
        $mati->update(['deactivated_at' => now(), 'deactivated_by' => $admin->id]);

        $this->assertTrue($aktif->fresh()->isActive());
        $this->assertFalse($mati->fresh()->isActive());

        $ids = Title::active()->pluck('id')->all();
        $this->assertContains($aktif->id, $ids);
        $this->assertNotContains($mati->id, $ids);
    }
}
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=TitleLifecycleTest`
Expected: FAIL — `Failed asserting that false is true` (kolom `deleted_at` belum ada di `tb_titles`).

- [ ] **Step 3: Buat migrasi**

Buat `database/migrations/2026_08_03_000003_add_lifecycle_fields_to_tb_titles.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->softDeletes();
            $table->timestamp('deactivated_at')->nullable()->after('reject_note');
            $table->foreignId('deactivated_by')->nullable()->after('deactivated_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn('deactivated_at');
            $table->dropSoftDeletes();
        });
    }
};
```

> Istilah **"nonaktif"** dipakai, bukan "arsip": `TitleArchive` sudah menempati konsep berbeda (arsip karya yang sudah selesai).

- [ ] **Step 4: Perbarui model `Title`**

Di [`app/Models/Title.php`](../../../app/Models/Title.php), ubah baris 5–29 menjadi:

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Title extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'tb_titles';

    public const JENIS = ['artikel', 'buku'];
    public const TIPE = ['mandiri', 'kolaborasi'];
    public const STATUSES = ['draft', 'menunggu', 'disetujui', 'ditolak'];
    public const INDEKSASI = [
        'none', 'SINTA 1', 'SINTA 2', 'SINTA 3', 'SINTA 4', 'SINTA 5', 'SINTA 6',
        'Scopus Q1', 'Scopus Q2', 'Scopus Q3', 'Scopus Q4', 'Copernicus', 'WoS', 'DOAJ', 'Garuda',
    ];

    protected $fillable = [
        'title', 'code', 'jenis', 'indeksasi', 'tipe_naskah', 'scope_id', 'assigned_to', 'status', 'asal', 'slug',
        'created_by', 'approved_by', 'approved_at', 'reject_note',
        'target_terbit', 'jurnal_target', 'jurnal_link', 'template_link', 'apc_info', 'catatan_publikasi',
        'deactivated_at', 'deactivated_by',
    ];

    protected $casts = [
        'approved_at'    => 'datetime',
        'target_terbit'  => 'date',
        'deactivated_at' => 'datetime',
    ];
```

Lalu tambahkan setelah `approver()`:

```php
    public function deactivatedBy()
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    /** Judul nonaktif tetap ada di laporan/papan/arsip, tapi hilang dari dropdown order. */
    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deactivated_at');
    }
```

- [ ] **Step 5: Jalankan test — harus LULUS**

Run: `php artisan test --filter=TitleLifecycleTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Jalankan seluruh suite**

Run: `php artisan test`
Expected: seluruh test lulus. Global scope soft delete baru di `Title` menyentuh Direktori Judul, Arsip, ISBN, dan papan manuskrip — kegagalan di sini wajib diselesaikan sebelum lanjut.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_03_000003_add_lifecycle_fields_to_tb_titles.php \
        app/Models/Title.php tests/Feature/TitleLifecycleTest.php
git commit -m "feat(judul): soft delete + kolom nonaktif pada tb_titles"
```

---

## Task 2: Partial `title-select` — label bersih di empat form order

**Files:**
- Create: `resources/views/orders/partials/title-select.blade.php`
- Modify: `resources/views/orders/book/create.blade.php`
- Modify: `resources/views/orders/journal/create.blade.php`
- Modify: `resources/views/orders/edit.blade.php`
- Modify: `resources/views/orders/journal/edit.blade.php`

- [ ] **Step 1: Buat partial**

Buat `resources/views/orders/partials/title-select.blade.php`:

```blade
{{--
  Dropdown judul bersama untuk KEEMPAT form order (buku/jurnal × create/edit).
  Menggantikan markup + JS yang sebelumnya tersalin empat kali.

  Aturan yang dikunci spec §2:
  · <option> berisi JUDUL SAJA. Kode / bidang ilmu / indeksasi hanya keterangan visual
    lewat data-* + templateResult, sehingga string "KODE — Judul" tidak pernah bisa
    ikut tersimpan ke tb_order_details.title.
  · Judul baru dikirim berprefix "new:" agar TitleService::resolveForOrder() tidak
    perlu menebak lewat is_numeric() — judul bernama "2026" dulu salah dibaca sbg id.
  · insertTag menaruh opsi "buat baru" di BAWAH hasil pencarian. Default Select2 4.x
    menaruhnya di ATAS, sehingga ketik-lalu-Enter cenderung membuat judul kembar
    alih-alih memilih judul yang sudah ada.

  Parameter:
    $titles    Collection<Title>   daftar judul yang boleh dipilih (sudah difilter controller)
    $selected  string|int|null     id judul terpilih, ATAU teks judul (data lama / prefill tagihan)
--}}
@php
    $selected = $selected ?? null;
    $selectedIsId = is_numeric($selected) && $titles->contains('id', (int) $selected);
@endphp

<select name="title_id" id="title_id" class="form-select title-select" required>
    <option value="">Pilih judul disetujui / ketik judul baru</option>
    @foreach ($titles as $t)
        <option value="{{ $t->id }}"
            data-code="{{ $t->code }}"
            data-scope="{{ $t->scope?->scope }}"
            data-tipe-naskah="{{ $t->tipe_naskah }}"
            data-scope-id="{{ $t->scope_id }}"
            data-indeksasi="{{ $t->indeksasi }}"
            {{ (string) $selected === (string) $t->id ? 'selected' : '' }}>{{ $t->title }}</option>
    @endforeach

    @if (filled($selected) && ! $selectedIsId)
        {{-- Judul lama yang belum tertaut Title, atau prefill dari tagihan: dikirim
             sebagai judul baru yang eksplisit, bukan string polos yang ambigu. --}}
        <option value="new:{{ $selected }}" selected>{{ $selected }}</option>
    @endif
</select>
<small class="text-muted">Pilih dari daftar judul disetujui, atau ketik judul baru bila belum ada.</small>

@push('custom-scripts')
<script>
    $(function () {
        var $sel = $('#title_id');
        if (!$sel.length) return;

        function metaOf(state) {
            if (!state.element || !state.element.dataset) return null;
            var d = state.element.dataset;
            var bits = [];
            if (d.code) bits.push(d.code);
            if (d.scope) bits.push(d.scope);
            if (d.indeksasi) bits.push(d.indeksasi);
            return bits.length ? bits.join(' · ') : null;
        }

        $sel.select2({
            tags: true,
            width: '100%',
            createTag: function (params) {
                var term = $.trim(params.term);
                if (term === '') return null;
                return { id: 'new:' + term, text: term, newTag: true };
            },
            insertTag: function (data, tag) { data.push(tag); },
            templateResult: function (state) {
                if (!state.id) return state.text;
                var $row = $('<span></span>').text(state.text);
                var sub = metaOf(state);
                if (sub) $row.append($('<small class="d-block text-muted"></small>').text(sub));
                return $row;
            },
            templateSelection: function (state) { return state.text; },
        });
    });
</script>
@endpush
```

- [ ] **Step 2: Pakai partial di form Tambah Order Buku**

Di [`resources/views/orders/book/create.blade.php`](../../../resources/views/orders/book/create.blade.php), ganti blok baris 48–64 (dari `<div class="mb-3">` berisi label "Judul" sampai `</div>` penutupnya) dengan:

```blade
                        <div class="mb-3">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            @include('orders.partials.title-select', [
                                'titles'   => $titles,
                                'selected' => old('title_id', $prefill['title'] ?? null),
                            ])
                        </div>
```

- [ ] **Step 3: Pakai partial di form Tambah Order Jurnal**

Di [`resources/views/orders/journal/create.blade.php`](../../../resources/views/orders/journal/create.blade.php), ganti blok baris 47–63 (label "Judul" + `<select name="title_id">` + `<small>`) dengan blok yang sama persis seperti Step 2.

- [ ] **Step 4: Pakai partial di form Edit Order Buku**

Di [`resources/views/orders/edit.blade.php`](../../../resources/views/orders/edit.blade.php), ganti blok baris 63–78 dengan:

```blade
                        <div class="mb-3">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            @include('orders.partials.title-select', [
                                'titles'   => $titles,
                                'selected' => old('title_id', $order->details->title_id ?: $order->details->title),
                            ])
                        </div>
```

- [ ] **Step 5: Pakai partial di form Edit Order Jurnal**

Di [`resources/views/orders/journal/edit.blade.php`](../../../resources/views/orders/journal/edit.blade.php), ganti blok baris 58–73 dengan:

```blade
                        <div class="mb-3">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            @include('orders.partials.title-select', [
                                'titles'   => $titles,
                                'selected' => old('title_id', $d->title_id ?: $d->title),
                            ])
                        </div>
```

- [ ] **Step 6: Pastikan skrip auto-isi tetap jalan**

Skrip auto-isi jenis/scope/indeksasi di keempat view membaca `document.getElementById('title_id')` dan `opt.dataset.tipeNaskah` — id dan atribut `data-*` itu **dipertahankan** partial, jadi skrip tersebut **tidak perlu diubah**. Verifikasi keempatnya masih ada:

Run: `grep -c "dataset.tipeNaskah" resources/views/orders/book/create.blade.php resources/views/orders/journal/create.blade.php resources/views/orders/edit.blade.php resources/views/orders/journal/edit.blade.php`
Expected: masing-masing `1`.

- [ ] **Step 7: Verifikasi tidak ada lagi label berkode**

Run: `grep -rn "code . ' — '" resources/views/orders/`
Expected: **tidak ada hasil** — keempat sumber string kotor sudah hilang.

- [ ] **Step 8: Test render keempat form**

Run: `php artisan test --filter="OrderJournalEditTest|RouteSmokeTest|DeepRouteSmokeTest"`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/views/orders/partials/title-select.blade.php \
        resources/views/orders/book/create.blade.php resources/views/orders/journal/create.blade.php \
        resources/views/orders/edit.blade.php resources/views/orders/journal/edit.blade.php
git commit -m "feat(order): partial title-select — dropdown judul menampilkan judul saja"
```

---

## Task 3: Nilai judul yang tak ambigu (`new:` prefix)

**Files:**
- Modify: `app/Services/TitleService.php`
- Modify: `app/Http/Controllers/Pages/OrderBookController.php`
- Modify: `app/Http/Controllers/Pages/OrderJournalController.php`
- Test: `tests/Feature/TitleSelectResolveTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/TitleSelectResolveTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Title;
use App\Models\User;
use App\Services\TitleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TitleSelectResolveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function actor(): User
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        return $u;
    }

    private function ctx(): array
    {
        return ['jenis' => 'buku', 'order_type' => 'bk_mandiri', 'scope_id' => null, 'indeksasi' => null];
    }

    /** @test */
    public function nilai_berprefix_new_menghasilkan_judul_baru(): void
    {
        $actor = $this->actor();

        $title = app(TitleService::class)->resolveForOrder('new:Judul Segar', $this->ctx(), $actor);

        $this->assertSame('Judul Segar', $title->title);
        $this->assertSame('order', $title->asal);
        $this->assertSame('disetujui', $title->status);
    }

    /** @test */
    public function nilai_angka_menaut_ke_judul_yang_ada(): void
    {
        $actor = $this->actor();
        $ada = Title::create([
            'title' => 'Judul Lama', 'code' => 'JL', 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'asal' => 'order',
            'created_by' => $actor->id,
        ]);

        $title = app(TitleService::class)->resolveForOrder((string) $ada->id, $this->ctx(), $actor);

        $this->assertTrue($title->is($ada));
        $this->assertSame(1, Title::count());
    }

    /** @test */
    public function judul_bernama_angka_jadi_judul_baru_bukan_id(): void
    {
        $actor = $this->actor();
        $ada = Title::create([
            'title' => 'Judul Lama', 'code' => 'JL', 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'asal' => 'order',
            'created_by' => $actor->id,
        ]);

        // Dulu: "2026" lolos is_numeric() → dibaca sebagai id judul.
        $title = app(TitleService::class)->resolveForOrder('new:2026', $this->ctx(), $actor);

        $this->assertSame('2026', $title->title);
        $this->assertFalse($title->is($ada));
        $this->assertSame(2, Title::count());
    }

    /** @test */
    public function string_polos_tetap_diterima_jalur_kompatibilitas(): void
    {
        $actor = $this->actor();

        $title = app(TitleService::class)->resolveForOrder('Judul Tanpa Prefix', $this->ctx(), $actor);

        $this->assertSame('Judul Tanpa Prefix', $title->title);
    }

    /** @test */
    public function judul_kembar_nama_dan_jenis_sama_dipakai_ulang(): void
    {
        $actor = $this->actor();
        $ada = Title::create([
            'title' => 'Judul Kembar', 'code' => 'JK', 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'asal' => 'order',
            'created_by' => $actor->id,
        ]);

        $title = app(TitleService::class)->resolveForOrder('new:Judul Kembar', $this->ctx(), $actor);

        $this->assertTrue($title->is($ada));
        $this->assertSame(1, Title::count());
    }

    /** @test */
    public function nama_judul_untuk_validasi_dipangkas_dari_prefix(): void
    {
        $actor = $this->actor();
        $ada = Title::create([
            'title' => 'Judul Ber-ID', 'code' => 'JBI', 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'asal' => 'order',
            'created_by' => $actor->id,
        ]);

        $svc = app(TitleService::class);
        $this->assertSame('Judul Baru', $svc->titleNameFrom('new:Judul Baru'));
        $this->assertSame('Judul Ber-ID', $svc->titleNameFrom((string) $ada->id));
        $this->assertSame('Judul Polos', $svc->titleNameFrom('Judul Polos'));
        $this->assertSame('', $svc->titleNameFrom('new:   '));
    }
}
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=TitleSelectResolveTest`
Expected: FAIL — judul dibuat dengan nama literal `new:Judul Segar`, dan `titleNameFrom()` belum ada.

- [ ] **Step 3: Tulis ulang `resolveForOrder()` + tambah `titleNameFrom()`**

Di [`app/Services/TitleService.php`](../../../app/Services/TitleService.php), ganti `resolveForOrder()` (baris 114–151) dengan:

```php
    /**
     * Resolusi judul untuk order. Keputusan diambil dari BENTUK nilai, bukan tebakan:
     *   angka          → id judul yang sudah ada
     *   berawalan new: → judul baru, namanya = sisa string setelah prefix
     *   string polos   → judul baru (kompatibilitas: old(), form lama, prefill tagihan)
     *
     * $ctx: jenis, order_type, scope_id?, indeksasi?.
     */
    public function resolveForOrder(int|string $value, array $ctx, User $actor): Title
    {
        [$id, $name] = $this->parseTitleValue($value);

        if ($id !== null) {
            $existing = Title::find($id);
            if ($existing) {
                return $existing;
            }
        }

        // Pakai ulang judul dengan nama + jenis sama (hindari duplikat & salah-taut
        // lintas jenis: order jurnal tak boleh menaut judul buku bernama sama).
        $existing = Title::where('title', $name)->where('jenis', $ctx['jenis'])->first();
        if ($existing) {
            return $existing;
        }

        return Title::create([
            'title'       => $name,
            'code'        => app(TitleCodeService::class)->generate($name),
            'jenis'       => $ctx['jenis'],
            'tipe_naskah' => str_contains($ctx['order_type'] ?? '', 'kolab') ? 'kolaborasi' : 'mandiri',
            'scope_id'    => $ctx['scope_id'] ?? null,
            'indeksasi'   => $ctx['indeksasi'] ?? null,
            'status'      => 'disetujui',
            'asal'        => 'order',
            'created_by'  => $actor->id,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * Nama judul dari nilai form — untuk validasi panjang & cek duplikat di controller.
     * Nilai berupa id dipetakan ke nama judulnya.
     */
    public function titleNameFrom(int|string $value): string
    {
        [$id, $name] = $this->parseTitleValue($value);

        if ($id !== null) {
            return Title::find($id)?->title ?? $name;
        }

        return $name;
    }

    /**
     * @return array{0: ?int, 1: string} [id judul bila nilainya angka, nama judul]
     */
    private function parseTitleValue(int|string $value): array
    {
        $raw = trim((string) $value);

        if (str_starts_with($raw, 'new:')) {
            return [null, trim(substr($raw, 4))];
        }

        // Sengaja preg_match, bukan is_numeric(): "2026" adalah id, " 2.5e3" bukan.
        if (preg_match('/^\d+$/', $raw) === 1) {
            return [(int) $raw, $raw];
        }

        return [null, $raw];
    }
```

- [ ] **Step 4: Jalankan test — harus LULUS**

Run: `php artisan test --filter=TitleSelectResolveTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Pakai helper + validasi di `OrderBookController::store()`**

Di [`app/Http/Controllers/Pages/OrderBookController.php`](../../../app/Http/Controllers/Pages/OrderBookController.php), ganti blok `$titleName = ...` (baris 186–189) dengan:

```php
        // Nama judul: prefix "new:" dipangkas, id dipetakan ke nama judulnya.
        $titleName = app(\App\Services\TitleService::class)->titleNameFrom($validate['title_id']);

        if ($titleName === '' || mb_strlen($titleName) > 255) {
            return redirect()->back()->withInput()
                ->withErrors(['title_id' => 'Judul wajib diisi dan maksimal 255 karakter.']);
        }
```

Naikkan juga batas mentah `title_id` di aturan validasi (baris 166) agar prefix tidak memotong judul sah:

```php
            'title_id'           => 'required|string|max:300',
```

- [ ] **Step 6: Validasi di `OrderBookController::update()`**

Tepat setelah blok `$request->validate([...])` di `update()` (setelah baris 401), tambahkan:

```php
        $titleName = app(\App\Services\TitleService::class)->titleNameFrom($request->title_id);
        if ($titleName === '' || mb_strlen($titleName) > 255) {
            return back()->withInput()
                ->withErrors(['title_id' => 'Judul wajib diisi dan maksimal 255 karakter.']);
        }
```

dan ubah aturan `title_id` di `update()` (baris 383) menjadi `'required|string|max:300'`.

- [ ] **Step 7: Ulangi untuk `OrderJournalController`**

Di [`app/Http/Controllers/Pages/OrderJournalController.php`](../../../app/Http/Controllers/Pages/OrderJournalController.php):

- `store()`: ubah aturan `title_id` (baris 72) menjadi `'required|string|max:300'`, lalu ganti blok `$titleName = ...` (baris 93–96) dengan blok yang sama persis seperti Step 5.
- `update()`: ubah aturan `title_id` (baris 262) menjadi `'required|string|max:300'`, lalu sisipkan blok validasi yang sama persis seperti Step 6 setelah `$request->validate([...])`.

- [ ] **Step 8: Dropdown menyaring judul nonaktif**

Di keempat method yang membangun `$titles` — `OrderBookController::create()` (baris 147), `OrderBookController::edit()` (baris 362), `OrderJournalController::create()` (baris 53), `OrderJournalController::edit()` (baris 243) — sisipkan `->active()` tepat setelah `Title::where('status', 'disetujui')`. Contoh untuk `OrderBookController::create()`:

```php
        $titles = Title::where('status', 'disetujui')->active()->where('jenis', 'buku')
            ->when(! Auth::user()->hasAnyRole(['manager', 'superadmin']), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('assigned_to')->orWhere('assigned_to', Auth::id());
                });
            })
            ->with('scope')->orderBy('title')->get();
```

> Judul yang sudah dihapus tersaring otomatis oleh global scope soft delete (Task 1) — tidak perlu klausa tambahan.

- [ ] **Step 9: Jalankan test order**

Run: `php artisan test --filter="TitleSelectResolveTest|OrderJournalEditTest|OrderEditGateTest"`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Services/TitleService.php \
        app/Http/Controllers/Pages/OrderBookController.php \
        app/Http/Controllers/Pages/OrderJournalController.php \
        tests/Feature/TitleSelectResolveTest.php
git commit -m "fix(judul): nilai title_id tak ambigu (prefix new:) + validasi nama judul"
```

---

## Task 4: Command `titles:strip-code-prefix`

**Files:**
- Create: `app/Console/Commands/StripTitleCodePrefix.php`
- Test: `tests/Feature/StripCodePrefixCommandTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/StripCodePrefixCommandTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StripCodePrefixCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function makeTitle(string $title, string $code): Title
    {
        $user = User::factory()->create();

        return Title::create([
            'title' => $title, 'code' => $code, 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'asal' => 'order',
            'created_by' => $user->id,
        ]);
    }

    /** @test */
    public function dry_run_tidak_mengubah_apa_pun(): void
    {
        $bersih = $this->makeTitle('Judul Bersih', 'JB');
        $kotor  = $this->makeTitle('JB — Judul Bersih', 'JBX');

        $this->artisan('titles:strip-code-prefix')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertSame('JB — Judul Bersih', $kotor->fresh()->title);
        $this->assertSame('Judul Bersih', $bersih->fresh()->title);
        $this->assertSame(0, \App\Models\TitleLog::count());
    }

    /** @test */
    public function apply_memangkas_hanya_baris_yang_kodenya_benar_benar_cocok(): void
    {
        $cocok = $this->makeTitle('JB — Judul Bersih', 'JB');

        // Kode "ZZZ" tidak terdaftar di tb_titles.code → tidak boleh disentuh.
        $palsu = $this->makeTitle('ZZZ — Judul Lain', 'QQ');

        $this->artisan('titles:strip-code-prefix --apply')->assertExitCode(0);

        $this->assertSame('Judul Bersih', $cocok->fresh()->title);
        $this->assertSame('ZZZ — Judul Lain', $palsu->fresh()->title);
        $this->assertDatabaseHas('tb_title_logs', [
            'title_id' => $cocok->id,
            'event'    => 'code_prefix_stripped',
        ]);
    }

    /** @test */
    public function judul_sah_bertanda_hubung_tidak_tersentuh(): void
    {
        $sah = $this->makeTitle('Pendidikan Anak Usia Dini — Sebuah Tinjauan', 'PAUD');

        $this->artisan('titles:strip-code-prefix --apply')->assertExitCode(0);

        $this->assertSame('Pendidikan Anak Usia Dini — Sebuah Tinjauan', $sah->fresh()->title);
    }

    /** @test */
    public function detail_order_ikut_dibersihkan(): void
    {
        $title = $this->makeTitle('Judul Buku', 'JBK');
        $order = Order::factory()->create();
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri',
            'title' => 'JBK — Judul Buku', 'slug' => 'judul-buku-' . $order->id,
            'title_id' => $title->id,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);

        $this->artisan('titles:strip-code-prefix --apply')->assertExitCode(0);

        $this->assertSame('Judul Buku', $detail->fresh()->title);
    }
}
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=StripCodePrefixCommandTest`
Expected: FAIL — `The command "titles:strip-code-prefix" does not exist.`

- [ ] **Step 3: Buat command**

Buat `app/Console/Commands/StripTitleCodePrefix.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Membersihkan judul yang terlanjur tersimpan sebagai "KODE — Judul".
 *
 * Sengaja command, BUKAN migrasi: isinya harus bisa dilihat dulu (dry run) sebelum
 * mengubah data produksi. Syarat kecocokan kode diperketat — prefix hanya dipangkas
 * bila kodenya benar-benar cocok dengan sebuah tb_titles.code — supaya judul yang
 * memang sah mengandung tanda hubung ("Pendidikan Anak Usia Dini — Sebuah Tinjauan")
 * tidak ikut terpangkas.
 */
class StripTitleCodePrefix extends Command
{
    protected $signature = 'titles:strip-code-prefix {--apply : Jalankan perubahan (tanpa flag = dry run)}';

    protected $description = 'Pangkas prefix "KODE — " dari tb_titles.title dan tb_order_details.title';

    private const PATTERN = '/^(?<code>[A-Za-z0-9\-\/]+)\s*[—\-]\s*(?<rest>.+)$/u';

    public function handle(): int
    {
        $codes = Title::withTrashed()
            ->whereNotNull('code')
            ->pluck('code')
            ->map(fn ($c) => mb_strtoupper(trim((string) $c)))
            ->filter()
            ->flip();

        $candidates = [];

        foreach (Title::withTrashed()->get(['id', 'title']) as $t) {
            $rest = $this->strippedName((string) $t->title, $codes);
            if ($rest !== null) {
                $candidates[] = ['tb_titles', $t->id, (string) $t->title, $rest, $t->id];
            }
        }

        foreach (OrderDetail::withTrashed()->get(['id', 'title', 'title_id']) as $d) {
            $rest = $this->strippedName((string) $d->title, $codes);
            if ($rest !== null) {
                $candidates[] = ['tb_order_details', $d->id, (string) $d->title, $rest, $d->title_id];
            }
        }

        if (empty($candidates)) {
            $this->info('Tidak ada judul berprefix kode. Tidak ada yang perlu diubah.');
            return self::SUCCESS;
        }

        $this->table(
            ['Tabel', 'ID', 'Nilai sekarang', 'Nilai sesudah'],
            array_map(fn ($row) => [$row[0], $row[1], $row[2], $row[3]], $candidates)
        );

        if (! $this->option('apply')) {
            $this->warn('DRY RUN — tidak ada yang diubah. Jalankan ulang dengan --apply untuk menerapkan.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($candidates) {
            foreach ($candidates as [$table, $id, $before, $after, $titleId]) {
                if ($table === 'tb_titles') {
                    Title::withTrashed()->where('id', $id)->update(['title' => $after]);
                } else {
                    OrderDetail::withTrashed()->where('id', $id)->update(['title' => $after]);
                }

                if ($titleId) {
                    TitleLog::create([
                        'title_id'   => $titleId,
                        'event'      => 'code_prefix_stripped',
                        'note'       => $table . '#' . $id . ': "' . $before . '" → "' . $after . '"',
                        'changed_by' => null,
                        'created_at' => now(),
                    ]);
                }
            }
        });

        $this->info(count($candidates) . ' baris dibersihkan.');

        return self::SUCCESS;
    }

    /**
     * Nama judul tanpa prefix kode — atau null bila baris ini tidak boleh disentuh.
     *
     * @param  \Illuminate\Support\Collection<string,int>  $codes  kode terdaftar (uppercase) sebagai kunci
     */
    private function strippedName(string $value, $codes): ?string
    {
        if (preg_match(self::PATTERN, $value, $m) !== 1) {
            return null;
        }

        $code = mb_strtoupper(trim($m['code']));
        $rest = trim($m['rest']);

        if ($rest === '' || ! $codes->has($code)) {
            return null;
        }

        return $rest;
    }
}
```

- [ ] **Step 4: Jalankan test — harus LULUS**

Run: `php artisan test --filter=StripCodePrefixCommandTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Dry run di DB dev — laporkan temuannya**

Run: `php artisan titles:strip-code-prefix`
Expected: tabel `Tabel · ID · Nilai sekarang · Nilai sesudah` diikuti peringatan `DRY RUN`.

**BERHENTI DI SINI.** Laporkan hasil dry run ke user dan **minta izin eksplisit** sebelum menjalankan `--apply`. Isi database produksi belum pernah diperiksa; `--apply` mengubah data nyata.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/StripTitleCodePrefix.php tests/Feature/StripCodePrefixCommandTest.php
git commit -m "feat(judul): command titles:strip-code-prefix (dry run + --apply)"
```

---

## Task 5: Edit judul berstatus `disetujui`

**Files:**
- Modify: `app/Models/Title.php`
- Modify: `app/Services/TitleService.php`
- Modify: `app/Http/Controllers/Pages/TitleController.php`
- Test: `tests/Feature/TitleEditApprovedTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/TitleEditApprovedTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TitleEditApprovedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function approvedTitle(User $creator): Title
    {
        return Title::create([
            'title' => 'Judul Salah Ketik', 'code' => 'JSK', 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'asal' => 'order',
            'created_by' => $creator->id,
        ]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'title' => 'Judul Sudah Benar', 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'indeksasi' => null,
            'scope_id' => null, 'assigned_to' => null, 'chapters' => [],
        ], $override);
    }

    /** @test */
    public function admin_bisa_mengedit_judul_disetujui(): void
    {
        $admin = $this->user('admin');
        $title = $this->approvedTitle($admin);

        $this->actingAs($admin)->get(route('title.edit', $title->id))->assertOk();

        $this->actingAs($admin)->put(route('title.update', $title->id), $this->payload())
            ->assertRedirect(route('title.show', $title->id));

        $title->refresh();
        $this->assertSame('Judul Sudah Benar', $title->title);
        $this->assertSame('disetujui', $title->status, 'Status tidak boleh turun ke menunggu setelah diedit.');
        $this->assertStringContainsString('judul-sudah-benar', $title->slug);
    }

    /** @test */
    public function production_ditolak_mengedit_judul_disetujui(): void
    {
        $title = $this->approvedTitle($this->user('admin'));

        $this->actingAs($this->user('production'))->get(route('title.edit', $title->id))->assertForbidden();
        $this->actingAs($this->user('production'))
            ->put(route('title.update', $title->id), $this->payload())->assertForbidden();
    }

    /** @test */
    public function production_tetap_bisa_mengedit_judul_draft(): void
    {
        $prod = $this->user('production');
        $title = Title::create([
            'title' => 'Draf Produksi', 'code' => 'DP', 'jenis' => 'artikel',
            'tipe_naskah' => 'mandiri', 'status' => 'draft', 'asal' => 'distribusi',
            'created_by' => $prod->id,
        ]);

        $this->actingAs($prod)->get(route('title.edit', $title->id))->assertOk();
    }

    /** @test */
    public function perubahan_teks_judul_tersinkron_ke_detail_order_dan_tercatat(): void
    {
        $admin = $this->user('admin');
        $title = $this->approvedTitle($admin);
        $order = Order::factory()->create();

        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri',
            'title' => 'Judul Salah Ketik', 'slug' => 'judul-salah-ketik-' . $order->id,
            'title_id' => $title->id,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);

        $this->actingAs($admin)->put(route('title.update', $title->id), $this->payload())->assertRedirect();

        $this->assertSame('Judul Sudah Benar', $detail->fresh()->title);
        $this->assertDatabaseHas('tb_title_logs', [
            'title_id'   => $title->id,
            'event'      => 'updated',
            'changed_by' => $admin->id,
        ]);
    }

    /** @test */
    public function kode_judul_tidak_ikut_berubah(): void
    {
        $admin = $this->user('admin');
        $title = $this->approvedTitle($admin);

        $this->actingAs($admin)->put(route('title.update', $title->id), $this->payload())->assertRedirect();

        $this->assertSame('JSK', $title->fresh()->code, 'Kode sudah tercetak di invoice & arsip — tidak boleh berubah otomatis.');
    }
}
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=TitleEditApprovedTest`
Expected: FAIL — `admin_bisa_mengedit_judul_disetujui` mendapat 403 (`Title::isEditable()` masih menolak `disetujui`).

- [ ] **Step 3: Perlebar `Title::isEditable()`**

Di [`app/Models/Title.php`](../../../app/Models/Title.php), ganti `isEditable()`:

```php
    /**
     * Judul disetujui SENGAJA ikut editable: hampir semua judul lahir dari order dan
     * langsung berstatus 'disetujui' (TitleService::resolveForOrder), jadi aturan lama
     * mengunci setiap salah ketik selamanya. Status TIDAK turun ke 'menunggu' setelah
     * diedit; siapa yang boleh mengedit judul disetujui dijaga TitleController.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'ditolak', 'disetujui'], true);
    }
```

- [ ] **Step 4: Sinkron + log di `TitleService::update()`**

Di [`app/Services/TitleService.php`](../../../app/Services/TitleService.php), tambahkan `use App\Models\OrderDetail;` di bagian import, lalu ganti `update()` (baris 40–57) dengan:

```php
    /** Perbarui judul + bab, sinkronkan teks judul ke order tertaut, dan catat perubahannya. */
    public function update(Title $title, array $data, array $chapters, User $actor): void
    {
        $labels = [
            'title'       => 'Judul',
            'jenis'       => 'Jenis',
            'indeksasi'   => 'Indeksasi',
            'tipe_naskah' => 'Tipe naskah',
            'scope_id'    => 'Bidang ilmu',
            'assigned_to' => 'Distribusi',
        ];

        $next = [
            'title'       => $data['title'],
            'jenis'       => $data['jenis'],
            'indeksasi'   => $data['indeksasi'] ?? null,
            'tipe_naskah' => $data['tipe_naskah'],
            'scope_id'    => $this->resolveScopeId($data['scope_id'] ?? null),
            'assigned_to' => ! empty($data['assigned_to']) ? (int) $data['assigned_to'] : null,
        ];

        $changed = [];
        foreach ($labels as $field => $label) {
            if ((string) ($title->$field ?? '') !== (string) ($next[$field] ?? '')) {
                $changed[] = $label;
            }
        }

        $renamed = $title->title !== $next['title'];

        DB::transaction(function () use ($title, $next, $chapters, $renamed) {
            $title->update($next);

            if ($renamed) {
                $title->update(['slug' => Str::slug($title->title) . '-' . $title->id]);

                // withTrashed(): order yang dibatalkan pun tidak boleh menyimpan judul basi.
                // Kode judul SENGAJA tidak ikut berubah — sudah tercetak di invoice & arsip.
                OrderDetail::withTrashed()
                    ->where('title_id', $title->id)
                    ->update(['title' => $title->title]);
            }

            if ($title->jenis === 'buku') {
                $this->syncChapters($title, $chapters);
            } else {
                $title->chapters()->delete();
            }
        });

        TitleLog::create([
            'title_id'   => $title->id,
            'event'      => 'updated',
            'note'       => $changed ? implode(', ', $changed) . ' diperbarui' : 'Judul disimpan',
            'changed_by' => $actor->id,
            'created_at' => now(),
        ]);
    }
```

- [ ] **Step 5: Penjagaan role di `TitleController`**

Di [`app/Http/Controllers/Pages/TitleController.php`](../../../app/Http/Controllers/Pages/TitleController.php), tambahkan metode privat setelah `isApprover()`:

```php
    /**
     * Judul berstatus 'disetujui' hanya boleh diedit oleh himpunan role yang sama
     * dengan canEditInfo di show() — 'production' memegang title.edit di matriks akses
     * tapi tidak boleh menyentuh judul yang sudah disetujui.
     */
    private function canEditApproved(): bool
    {
        return Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin']);
    }
```

Ganti `edit()` (baris 117–128):

```php
    public function edit(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::with('chapters')->findOrFail($id);
        abort_unless($title->isEditable(), 403);
        abort_if($title->isApproved() && ! $this->canEditApproved(), 403);

        return view('titles.form', [
            'title' => $title,
            'scopes' => Scope::orderBy('scope')->get(),
            'marketers' => User::role('marketing')->orderBy('name')->get(),
        ]);
    }
```

Ganti `update()` (baris 130–139):

```php
    public function update(Request $request, int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::findOrFail($id);
        abort_unless($title->isEditable(), 403);
        abort_if($title->isApproved() && ! $this->canEditApproved(), 403);
        $data = $this->validateData($request);
        $this->service->update($title, $data, $request->input('chapters', []), Auth::user());

        return redirect()->route('title.show', $title->id)->with('success', 'Judul diperbarui.');
    }
```

- [ ] **Step 6: Jalankan test — harus LULUS**

Run: `php artisan test --filter=TitleEditApprovedTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Jalankan test judul lain**

Run: `php artisan test --filter="TitleLifecycleTest|TitleSelectResolveTest|ArchiveGroupedTitlesTest|DocChecklistTest|BookIsbnTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Models/Title.php app/Services/TitleService.php \
        app/Http/Controllers/Pages/TitleController.php tests/Feature/TitleEditApprovedTest.php
git commit -m "feat(judul): judul disetujui bisa diedit + sinkron judul ke detail order"
```

---

## Task 6: Hapus & nonaktifkan judul

> **Prasyarat:** Task 1 rencana [`2026-08-03-order-cancel-edit.md`](2026-08-03-order-cancel-edit.md) harus sudah selesai (trait `SoftDeletes` di `OrderDetail`), agar "order aktif" tidak menghitung order yang dibatalkan.

**Files:**
- Modify: `app/Models/Title.php`
- Modify: `app/Http/Controllers/Pages/TitleController.php`
- Modify: `routes/web.php`
- Modify: `config/permissions.php`
- Modify: `database/seeders/AccessMatrixSeeder.php`
- Test: `tests/Feature/TitleLifecycleTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan `use` di `tests/Feature/TitleLifecycleTest.php`:

```php
use App\Models\BookIsbn;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Services\OrderCancellationService;
```

dan test-test ini:

```php
    private function linkOrder(Title $title): OrderDetail
    {
        $order = Order::factory()->create();

        return OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri',
            'title' => $title->title, 'slug' => 'judul-' . $order->id,
            'title_id' => $title->id,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);
    }

    /** @test */
    public function judul_tanpa_pemakaian_bisa_dihapus(): void
    {
        $admin = $this->user('admin');
        $title = $this->makeTitle($admin);

        $this->assertTrue($title->isDeletable());
        $this->assertNull($title->deleteBlockReason());

        $this->actingAs($admin)->delete(route('title.destroy', $title->id))
            ->assertRedirect(route('title.index'))->assertSessionHas('success');

        $this->assertSoftDeleted('tb_titles', ['id' => $title->id]);
        $this->assertDatabaseHas('tb_title_logs', ['title_id' => $title->id, 'event' => 'deleted']);
    }

    /** @test */
    public function judul_dengan_order_aktif_tidak_bisa_dihapus(): void
    {
        $admin = $this->user('admin');
        $title = $this->makeTitle($admin);
        $this->linkOrder($title);

        $this->assertFalse($title->fresh()->isDeletable());
        $this->assertSame('Dipakai 1 order', $title->fresh()->deleteBlockReason());

        $this->actingAs($admin)->delete(route('title.destroy', $title->id))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('tb_titles', ['id' => $title->id, 'deleted_at' => null]);
    }

    /** @test */
    public function judul_dengan_isbn_tidak_bisa_dihapus(): void
    {
        $admin = $this->user('admin');
        $title = $this->makeTitle($admin);
        // Status ISBN yang dikenal: pendaftaran | ber_isbn | cetak (BookIsbn::STATUSES).
        BookIsbn::create(['title_id' => $title->id, 'status' => 'pendaftaran', 'created_by' => $admin->id]);

        $this->assertSame('Sudah punya ISBN', $title->fresh()->deleteBlockReason());
    }

    /** @test */
    public function judul_yatim_karena_order_dibatalkan_bisa_dihapus(): void
    {
        $admin  = $this->user('admin');
        $owner  = $this->user('marketing');
        $title  = $this->makeTitle($admin);
        $detail = $this->linkOrder($title);

        $this->assertFalse($title->fresh()->isDeletable());

        app(OrderCancellationService::class)->cancel($detail->order, null, $owner);

        // OrderDetail ikut soft-deleted → judul tidak lagi punya order aktif.
        $this->assertTrue($title->fresh()->isDeletable());
    }

    /** @test */
    public function nonaktifkan_menyembunyikan_judul_dari_dropdown_order(): void
    {
        $admin = $this->user('admin');
        $title = $this->makeTitle($admin);

        $this->actingAs($admin)->post(route('title.deactivate', $title->id))
            ->assertRedirect()->assertSessionHas('success');

        $title->refresh();
        $this->assertFalse($title->isActive());
        $this->assertSame($admin->id, $title->deactivated_by);
        $this->assertDatabaseHas('tb_title_logs', ['title_id' => $title->id, 'event' => 'deactivated']);

        // Hilang dari dropdown order, TAPI masih ada di direktori & laporan.
        $this->actingAs($this->user('marketing'))->get(route('order.book.create'))
            ->assertOk()->assertDontSee('Judul Uji');
        $this->assertSame(1, Title::count());
    }

    /** @test */
    public function aktifkan_lagi_hanya_manager_atau_superadmin(): void
    {
        $admin = $this->user('admin');
        $title = $this->makeTitle($admin);
        $title->update(['deactivated_at' => now(), 'deactivated_by' => $admin->id]);

        $this->actingAs($admin)->post(route('title.activate', $title->id))->assertForbidden();

        $this->actingAs($this->user('manager'))->post(route('title.activate', $title->id))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertTrue($title->fresh()->isActive());
        $this->assertDatabaseHas('tb_title_logs', ['title_id' => $title->id, 'event' => 'activated']);
    }

    /** @test */
    public function production_tidak_bisa_menonaktifkan(): void
    {
        $title = $this->makeTitle($this->user('admin'));

        $this->actingAs($this->user('production'))
            ->post(route('title.deactivate', $title->id))->assertForbidden();
    }
```

- [ ] **Step 2: Jalankan test — harus GAGAL**

Run: `php artisan test --filter=TitleLifecycleTest`
Expected: FAIL — `Call to undefined method App\Models\Title::isDeletable()`.

- [ ] **Step 3: Metode pendukung di model `Title`**

Di [`app/Models/Title.php`](../../../app/Models/Title.php), tambahkan setelah `scopeActive()`:

```php
    /**
     * Alasan judul TIDAK boleh dihapus, atau null bila boleh.
     *
     * "Order aktif" dihitung tanpa withTrashed() — disengaja: judul yang jadi yatim
     * karena order-nya dibatalkan memang seharusnya bisa dibersihkan. Karena hapusnya
     * soft, tb_order_details.title_id yang ber-nullOnDelete tidak ikut dikosongkan,
     * jadi riwayat tetap tertaut.
     */
    public function deleteBlockReason(): ?string
    {
        $orders = $this->orderDetails()->count();
        if ($orders > 0) {
            return 'Dipakai ' . $orders . ' order';
        }

        if ($this->bookIsbn()->exists()) {
            return 'Sudah punya ISBN';
        }

        if ($this->archive()->exists()) {
            return 'Sudah diarsipkan';
        }

        return null;
    }

    public function isDeletable(): bool
    {
        return $this->deleteBlockReason() === null;
    }
```

- [ ] **Step 4: Controller `destroy()`, `deactivate()`, `activate()`**

Di [`app/Http/Controllers/Pages/TitleController.php`](../../../app/Http/Controllers/Pages/TitleController.php), tambahkan `use App\Models\TitleLog;` di bagian import, lalu ganti `destroy()` (baris 141–149) dengan:

```php
    public function destroy(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::findOrFail($id);

        // Aturan lama ("hanya draf milik sendiri") diganti aturan pemakaian: judul yang
        // sudah terpakai tidak dihapus tapi dinonaktifkan (spec §4.2).
        $reason = $title->deleteBlockReason();
        if ($reason !== null) {
            return back()->with('error',
                'Judul tidak bisa dihapus — ' . $reason . '. Gunakan Nonaktifkan bila judul ini sudah tidak dipakai lagi.');
        }

        TitleLog::create([
            'title_id'   => $title->id,
            'event'      => 'deleted',
            'note'       => 'Judul dihapus (belum terpakai order/ISBN/arsip).',
            'changed_by' => Auth::id(),
            'created_at' => now(),
        ]);

        $title->delete();

        return redirect()->route('title.index')->with('success', 'Judul dihapus.');
    }

    /** Judul nonaktif hilang dari dropdown order & daftar direktori default — laporan tetap utuh. */
    public function deactivate(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::findOrFail($id);

        if (! $title->isActive()) {
            return back()->with('error', 'Judul ini sudah nonaktif.');
        }

        $title->update(['deactivated_at' => now(), 'deactivated_by' => Auth::id()]);

        TitleLog::create([
            'title_id'   => $title->id,
            'event'      => 'deactivated',
            'note'       => 'Judul dinonaktifkan.',
            'changed_by' => Auth::id(),
            'created_at' => now(),
        ]);

        return back()->with('success', 'Judul dinonaktifkan.');
    }

    public function activate(int $id)
    {
        abort_unless($this->isApprover(), 403); // manager | superadmin
        $title = Title::findOrFail($id);

        if ($title->isActive()) {
            return back()->with('error', 'Judul ini sudah aktif.');
        }

        $title->update(['deactivated_at' => null, 'deactivated_by' => null]);

        TitleLog::create([
            'title_id'   => $title->id,
            'event'      => 'activated',
            'note'       => 'Judul diaktifkan kembali.',
            'changed_by' => Auth::id(),
            'created_at' => now(),
        ]);

        return back()->with('success', 'Judul diaktifkan kembali.');
    }
```

- [ ] **Step 5: Daftar direktori menyaring judul nonaktif**

Di file yang sama, ganti `index()` (baris 30–51) dengan:

```php
    public function index(Request $request)
    {
        $showInactive = $request->boolean('inactive');

        $query = Title::with(['creator', 'scope', 'assignedMarketing', 'orderDetails.titleProgress'])
            ->withCount('orderDetails as orders_count')
            ->withCount(['orderDetails as authors_count' => function ($q) {
                $q->join('tb_author_orders', 'tb_author_orders.order_detail_id', '=', 'tb_order_details.id');
            }])
            ->when(! $showInactive, fn ($q) => $q->active())
            ->latest();

        if (! $this->canManage()) {
            // marketing: hanya disetujui, dan hanya yang tak di-assign (semua) atau di-assign ke dirinya
            $query->where('status', 'disetujui')
                ->where(function ($q) {
                    $q->whereNull('assigned_to')->orWhere('assigned_to', Auth::id());
                });
        }

        return view('titles.index', [
            'titles' => $query->get(),
            'canManage' => $this->canManage(),
            'isApprover' => $this->isApprover(),
            'showInactive' => $showInactive,
            'canEditApproved' => $this->canEditApproved(),
        ]);
    }
```

- [ ] **Step 6: Route baru**

Di [`routes/web.php`](../../../routes/web.php), tambahkan setelah baris 237 (`title.chapters.authors`):

```php
    Route::post('titles/{id}/deactivate', [TitleController::class, 'deactivate'])->name('title.deactivate')->whereNumber('id');
    Route::post('titles/{id}/activate', [TitleController::class, 'activate'])->name('title.activate')->whereNumber('id');
```

- [ ] **Step 7: Permission**

Di [`config/permissions.php`](../../../config/permissions.php), pada modul `title` (baris ~94), tambahkan setelah action `'delete'`:

```php
                'deactivate' => ['title.deactivate', 'title.activate'],
```

Di [`database/seeders/AccessMatrixSeeder.php`](../../../database/seeders/AccessMatrixSeeder.php), pada `$grants['admin']`, tambahkan `title.deactivate` ke baris permission judul:

```php
            'title.view', 'title.create', 'title.edit', 'title.delete', 'title.deactivate', 'title.submit', 'title.info',
```

> `production` **tidak** mendapat `title.deactivate`. manager & superadmin sudah mendapatkannya lewat hibah `'*'` / `Gate::before`.

- [ ] **Step 8: Jalankan test — harus LULUS**

Run: `php artisan test --filter=TitleLifecycleTest`
Expected: PASS (10 tests).

> Bila `nonaktifkan_menyembunyikan_judul_dari_dropdown_order` gagal karena route `order.book.create` menolak marketing, periksa bahwa `Title::active()` sudah dipasang di `OrderBookController::create()` (Task 3 Step 8).

- [ ] **Step 9: Test pemetaan permission**

Run: `php artisan test --filter="PermissionMapCompletenessTest|AccessParityTest|PermissionPageTest"`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Models/Title.php app/Http/Controllers/Pages/TitleController.php \
        routes/web.php config/permissions.php database/seeders/AccessMatrixSeeder.php \
        tests/Feature/TitleLifecycleTest.php
git commit -m "feat(judul): hapus judul belum terpakai + nonaktifkan/aktifkan"
```

---

## Task 7: UI Direktori Judul

**Files:**
- Modify: `resources/views/titles/index.blade.php`

- [ ] **Step 1: Toggle "Tampilkan judul nonaktif"**

Di [`resources/views/titles/index.blade.php`](../../../resources/views/titles/index.blade.php), ganti blok header (baris 14–21) dengan:

```blade
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Direktori Judul{{ $showInactive ? ' (termasuk nonaktif)' : '' }}</h5>
    <div class="d-flex gap-2">
        @if ($showInactive)
            <a href="{{ route('title.index') }}" class="btn btn-sm btn-outline-secondary">Sembunyikan judul nonaktif</a>
        @else
            <a href="{{ route('title.index', ['inactive' => 1]) }}" class="btn btn-sm btn-outline-secondary">Tampilkan judul nonaktif</a>
        @endif
        @if($canManage)
            @can('title.create')
            <a href="{{ route('title.create') }}" class="btn btn-sm btn-primary">Buat Judul</a>
            @endcan
        @endif
    </div>
</div>
```

- [ ] **Step 2: Tandai baris judul nonaktif**

Ganti baris status (baris 41) dengan:

```blade
                        <td>
                            <span class="badge {{ $sb[$t->status] ?? 'bg-secondary' }}">{{ $sl[$t->status] ?? $t->status }}</span>
                            @unless ($t->isActive())
                                <span class="badge bg-dark ms-1" title="Judul ini tidak muncul di dropdown Tambah Order">Nonaktif</span>
                            @endunless
                        </td>
```

- [ ] **Step 3: Tulis ulang kolom Aksi**

Ganti blok `<td>` Aksi (baris 43–58) dengan:

```blade
                        <td>
                            <a href="{{ route('title.show', $t->id) }}" class="btn btn-xs btn-outline-primary">Lihat</a>

                            @if ($canManage && $t->isEditable() && (! $t->isApproved() || $canEditApproved))
                                @can('title.edit')
                                <a href="{{ route('title.edit', $t->id) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                                @endcan
                            @endif

                            @if ($canManage && in_array($t->status, ['draft', 'ditolak'], true))
                                @can('title.submit')
                                <form action="{{ route('title.submit', $t->id) }}" method="POST" class="d-inline m-0">@csrf<button class="btn btn-xs btn-outline-info">Ajukan</button></form>
                                @endcan
                            @endif

                            @if($isApprover && $t->status === 'menunggu')
                                @can('title.approve')
                                <form action="{{ route('title.approve', $t->id) }}" method="POST" class="d-inline m-0">@csrf<button class="btn btn-xs btn-outline-success">Setujui</button></form>
                                @endcan
                            @endif

                            @if ($canManage)
                                @can('title.deactivate')
                                    @if ($t->isActive())
                                        <form action="{{ route('title.deactivate', $t->id) }}" method="POST" class="d-inline m-0"
                                              onsubmit="return confirm('Nonaktifkan judul ini? Judul akan hilang dari dropdown Tambah Order, tapi laporan dan papan manuskrip tetap utuh.')">
                                            @csrf<button class="btn btn-xs btn-outline-warning">Nonaktifkan</button>
                                        </form>
                                    @else
                                        <form action="{{ route('title.activate', $t->id) }}" method="POST" class="d-inline m-0">
                                            @csrf<button class="btn btn-xs btn-outline-success">Aktifkan</button>
                                        </form>
                                    @endif
                                @endcan

                                @can('title.delete')
                                    @php $blocked = $t->deleteBlockReason(); @endphp
                                    @if ($blocked)
                                        {{-- Tombol SENGAJA tidak disembunyikan: user perlu tahu KENAPA judul
                                             tak bisa dihapus, dan langsung melihat Nonaktifkan sbg jalan keluar. --}}
                                        <button type="button" class="btn btn-xs btn-outline-danger disabled" tabindex="-1"
                                                title="{{ $blocked }} — tidak bisa dihapus">Hapus</button>
                                    @else
                                        <form action="{{ route('title.destroy', $t->id) }}" method="POST" class="d-inline m-0"
                                              onsubmit="return confirm('Hapus judul ini? Judul belum dipakai order, ISBN, maupun arsip.')">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-xs btn-outline-danger">Hapus</button>
                                        </form>
                                    @endif
                                @endcan
                            @endif
                        </td>
```

- [ ] **Step 4: Jalankan test terkait**

Run: `php artisan test --filter="TitleLifecycleTest|TitleEditApprovedTest|PermissionButtonVisibilityTest|RouteSmokeTest"`
Expected: PASS.

- [ ] **Step 5: Buka aplikasinya**

Run: `php artisan migrate && php artisan db:seed --class=AccessMatrixSeeder`, lalu buka `/titles` sebagai admin.
Expected:
- Judul berstatus Disetujui kini punya tombol **Edit**;
- Judul yang dipakai order menampilkan tombol **Hapus** yang mati dengan tooltip "Dipakai N order";
- Tombol **Nonaktifkan** tersedia; setelah diklik, judul hilang dari daftar sampai toggle "Tampilkan judul nonaktif" dipakai, dan hilang dari dropdown di `/order/buku/create`;
- Sebagai manager, tombol **Aktifkan** muncul pada judul nonaktif.

- [ ] **Step 6: Commit**

```bash
git add resources/views/titles/index.blade.php
git commit -m "feat(judul): UI direktori — edit judul disetujui, hapus beralasan, nonaktif/aktif"
```

---

## Task 8: Verifikasi akhir

**Files:** tidak ada perubahan kode.

- [ ] **Step 1: Seluruh suite**

Run: `php artisan test`
Expected: seluruh test lulus. Perhatian khusus:
- `ArchiveGroupedTitlesTest`, `ArchivePdfTest`, `BookIsbnTest`, `DocChecklistTest`, `ChapterAuthorTest` — global scope soft delete `Title` menyentuh semuanya;
- `ManuscriptTrackerTest`, `ArticleDistributionTest`, `BookDistributionTest` — `Title::active()` tidak boleh menyaring apa pun di papan;
- `RouteSmokeTest` / `DeepRouteSmokeTest` — dua route baru tidak boleh 500.

- [ ] **Step 2: Migrasi & seed DB dev**

Run: `php artisan migrate && php artisan db:seed --class=AccessMatrixSeeder`
Expected: migrasi `2026_08_03_000003` tereksekusi; permission `title.deactivate` tercipta.

- [ ] **Step 3: Cek halaman Hak Akses**

Buka `/hak-akses` sebagai superadmin.
Expected: modul Direktori Judul kini menampilkan action **deactivate**.

- [ ] **Step 4: Dry run pembersihan data**

Run: `php artisan titles:strip-code-prefix`
Expected: laporan baris yang akan dibersihkan. Laporkan hasilnya ke user; **jangan** jalankan `--apply` tanpa izin.

- [ ] **Step 5: Perbarui status spec**

Di [`docs/superpowers/specs/2026-08-03-order-cancel-title-management-design.md`](../specs/2026-08-03-order-cancel-title-management-design.md), perbarui baris `**Status:**` menjadi:

```markdown
**Status:** Terimplementasi 2026-08-03 — Bagian 1 di `docs/superpowers/plans/2026-08-03-order-cancel-edit.md`, Bagian 2a/2b/2c di `docs/superpowers/plans/2026-08-03-title-management.md`. Sisa: `php artisan titles:strip-code-prefix --apply` menunggu izin user.
```

```bash
git add docs/superpowers/specs/2026-08-03-order-cancel-title-management-design.md
git commit -m "docs: tandai spec pengelolaan judul sebagai terimplementasi"
```

---

## Catatan risiko

**Global scope soft delete di `Title` (Task 1) adalah perubahan paling luas di rencana ini.** `Title::` di-query langsung dari Direktori Judul, Arsip Judul, Registri ISBN, Cek Kelengkapan Dokumen, Author per Bab, papan manuskrip, dan keempat form order. Semua otomatis menyaring judul terhapus — itu memang tujuannya — tapi berarti satu kesalahan trait terasa di seluruh aplikasi. Step 6 Task 1 menjalankan seluruh suite sebelum melangkah, jangan dilewati.

**Tidak ada tombol Pulihkan untuk judul pada iterasi ini** (spec §4.2). Datanya tetap utuh di database dan bisa dikembalikan lewat `tinker` (`Title::withTrashed()->find($id)->restore()`) bila benar-benar perlu. Syarat hapusnya sudah ketat — yang bisa terhapus hanyalah judul yang belum terpakai apa pun — sehingga UI pemulihan dinilai belum sepadan.

**`titles:strip-code-prefix --apply` mengubah data produksi.** Isi database produksi belum pernah diperiksa selama perancangan (koneksi `.env` menunjuk host remote). Dry run adalah langkah verifikasi pertama, dan `--apply` hanya dijalankan setelah user melihat laporannya.
