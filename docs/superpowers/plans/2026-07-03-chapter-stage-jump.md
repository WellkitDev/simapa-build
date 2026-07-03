# Chapter Stage Jump (UI Lompat/Mundur Tahap Bab) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Beri UI di panel bab papan manuskrip untuk memindahkan tahap sebuah bab buku ke tahap tujuan mana pun (maju-lompat / mundur) dengan catatan, memakai modal SweetAlert2.

**Architecture:** Fitur murni UI. Backend sudah lengkap: `ChapterManuscriptService::changeStatus($cp, $target, $actor, $note)` menerima tahap tujuan apa pun + otorisasi per-role + catatan wajib untuk perpindahan non-next; endpoint `POST chapter.advance` sudah membaca `status` + `note`. Kita hanya menambah kontrol "Ubah…" per bab (`card.blade.php`) dan handler modal (`board.blade.php`) yang mem-POST `{status, note}` ke endpoint yang sama.

**Tech Stack:** Laravel 11 Blade, Bootstrap 5, SweetAlert2 (global), vanilla `fetch`. Test: PHPUnit/Laravel feature test via `.env.testing`.

---

## File Structure

- `tests/Feature/ChapterStageJumpTest.php` (**create**) — feature test endpoint jump/mundur + render kontrol.
- `resources/views/manuscript/partials/card.blade.php` (**modify**) — tombol "Ubah…" per bab (di blok `@if($cp)`, di samping tombol "Maju → next").
- `resources/views/manuscript/board.blade.php` (**modify**) — `const CHAPTER_STAGES` (label tahap dari PHP) + handler klik `[data-chapter-edit]` (modal SweetAlert2 → fetch advance).

**Tak diubah** (dipakai apa adanya, jangan sentuh): `app/Services/ChapterManuscriptService.php`, `app/Http/Controllers/Pages/ChapterProgressController.php`, route `chapter.advance`.

---

## Catatan konteks untuk implementer

**Tahap buku** (`App\Models\TitleProgress::BOOK_STAGES`): `['menunggu_proses','editing','layout','proofreading','isbn','cetak','terbit']`. Handler tiap tahap (`STAGE_HANDLER`): `editing/layout/proofreading/isbn` = `production`; `cetak/terbit` = `superadmin`; `menunggu_proses` = default `superadmin`. `Title::stageLabel($s)` memberi label tampilan.

**Aturan `changeStatus`:** `next` = tahap sesudah `current`. `isCorrection = (target !== next)`. Bila correction & `note` kosong → `ValidationException` (pesan "Catatan wajib untuk koreksi/lompat."). Otorisasi: superadmin/manager bebas; production hanya bila handler tahap **saat ini** = production. `needs_review = isCorrection && ! superadmin`.

**Endpoint** `chapter.advance` (`ChapterProgressController@advance`): saat request `expectsJson`, exception `ValidationException` → HTTP 422, `AuthorizationException` → HTTP 403; sukses → JSON `{ok:true, id, status, message}`. Non-JSON → redirect back dgn flash.

**Fixture bab** (pola dari `tests/Feature/ChapterProgressControllerTest.php`): buat Title buku + Order + OrderDetail(`chapters=2`) + TitleProgress + `ChapterManuscriptService::ensureChapters($book)` → bab + ChapterProgress ter-seed pada status TitleProgress.

---

### Task 1: Feature test endpoint jump/mundur (kunci wiring backend↔endpoint)

**Files:**
- Create: `tests/Feature/ChapterStageJumpTest.php`

> Test ini menegaskan endpoint `chapter.advance` sudah meneruskan `status` + `note` ke `changeStatus`. Karena backend sudah jadi, test **diharapkan langsung PASS**; bila ada yang gagal berarti wiring endpoint rusak dan harus diperbaiki sebelum lanjut.

- [ ] **Step 1: Tulis test file lengkap**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\ChapterProgress;
use App\Services\ChapterManuscriptService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ChapterStageJumpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** Buku + 2 bab; kembalikan ChapterProgress bab pertama (status awal 'editing'). */
    private function firstChapter(): ChapterProgress
    {
        $owner = $this->user('production');
        $book = Title::create(['title' => 'Buku Lompat', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-SJ-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => 'Buku Lompat', 'slug' => 'buku-lompat', 'chapters' => 2, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production', 'started_at' => now()]);
        app(ChapterManuscriptService::class)->ensureChapters($book);
        return $book->chapters()->with('progress')->orderBy('urutan')->first()->progress;
    }

    /** @test */
    public function manager_moves_chapter_backward_with_note(): void
    {
        $cp = $this->firstChapter();
        $cp->update(['status' => 'layout']); // maju dulu supaya bisa mundur

        $this->actingAs($this->user('manager'))
            ->postJson(route('chapter.advance', $cp->id), ['status' => 'editing', 'note' => 'perlu perbaikan'])
            ->assertOk()->assertJson(['ok' => true, 'status' => 'editing']);

        $fresh = $cp->fresh();
        $this->assertSame('editing', $fresh->status);
        $this->assertSame('perlu perbaikan', $fresh->note);
        $this->assertTrue((bool) $fresh->needs_review); // manager (non-superadmin) + koreksi
    }

    /** @test */
    public function jump_without_note_is_rejected(): void
    {
        $cp = $this->firstChapter(); // editing; next = layout

        $this->actingAs($this->user('manager'))
            ->postJson(route('chapter.advance', $cp->id), ['status' => 'terbit']) // lompat tanpa catatan
            ->assertStatus(422);

        $this->assertSame('editing', $cp->fresh()->status);
    }

    /** @test */
    public function production_cannot_move_superadmin_stage_chapter(): void
    {
        $cp = $this->firstChapter();
        $cp->update(['status' => 'cetak']); // handler 'cetak' = superadmin

        $this->actingAs($this->user('production'))
            ->postJson(route('chapter.advance', $cp->id), ['status' => 'isbn', 'note' => 'mundur'])
            ->assertStatus(403);

        $this->assertSame('cetak', $cp->fresh()->status);
    }
}
```

- [ ] **Step 2: Jalankan test — diharapkan PASS (bukti endpoint sudah threading note)**

Run: `php artisan test --env=testing tests/Feature/ChapterStageJumpTest.php`
Expected: 3 passed. Bila `manager_moves_chapter_backward_with_note` gagal karena `note` tak tersimpan → periksa `ChapterProgressController@advance` meneruskan `$request->input('note')` (seharusnya sudah). Jangan ubah service/controller kecuali benar-benar rusak.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ChapterStageJumpTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
test(chapter-jump): endpoint threads target+note (backward, note-required, prod authz)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 2: Tombol "Ubah…" per bab + test render papan

**Files:**
- Modify: `resources/views/manuscript/partials/card.blade.php` (blok bab `@if($cp) … </div>` sekitar baris 154–166)
- Modify: `tests/Feature/ChapterStageJumpTest.php` (tambah 1 test render)

- [ ] **Step 1: Tambah test render (gagal dulu)**

Tambahkan method ini di dalam `ChapterStageJumpTest` (sebelum kurung tutup kelas):

```php
    /** @test */
    public function board_renders_ubah_control_for_book_chapter(): void
    {
        $owner = $this->user('production');
        $book = Title::create(['title' => 'Buku Render', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-RD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => 'Buku Render', 'slug' => 'buku-render', 'chapters' => 2, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        app(\App\Services\TitleProgressService::class)->createForDetail($detail, $owner->id);

        $this->actingAs($this->user('manager'))->get(route('manuscript.board', ['tipe' => 'buku']))
            ->assertOk()->assertSee('data-chapter-edit', false);
    }
```

- [ ] **Step 2: Jalankan — diharapkan GAGAL (tombol belum ada)**

Run: `php artisan test --env=testing tests/Feature/ChapterStageJumpTest.php::board_renders_ubah_control_for_book_chapter`
Expected: FAIL — `assertSee('data-chapter-edit')` tidak ditemukan.

- [ ] **Step 3: Tambah tombol "Ubah…" di panel bab**

Di `resources/views/manuscript/partials/card.blade.php`, di dalam blok `@if($cp) <div class="d-flex gap-1 mt-1"> … </div>`, tepat setelah tombol "Maju → …" (blok `@if($cnext) … @endif`), sisipkan tombol berikut (sebelum `<select … data-chapter-assign>`):

```blade
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0"
                                        data-chapter-edit data-cp="{{ $cp->id }}"
                                        data-current="{{ $cstatus }}" data-next="{{ $cnext ?? '' }}"
                                        data-judul="{{ $ch->urutan }}. {{ $ch->judul }}"
                                        style="font-size:10px">Ubah…</button>
```

- [ ] **Step 4: Jalankan test render — diharapkan PASS**

Run: `php artisan test --env=testing tests/Feature/ChapterStageJumpTest.php::board_renders_ubah_control_for_book_chapter`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/manuscript/partials/card.blade.php tests/Feature/ChapterStageJumpTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(chapter-jump): tombol "Ubah…" per bab di panel papan manuskrip

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 3: Modal SweetAlert2 + handler `[data-chapter-edit]` di board.blade.php

**Files:**
- Modify: `resources/views/manuscript/board.blade.php` (dalam `@push('scripts') … (function(){ … })()` — di dekat handler `[data-chapter-advance]` sekitar baris 184–195)

> `base`, `token`, `toast`, dan `Swal` sudah tersedia di scope script papan (dipakai handler advance/assign). Handler baru meniru pola fetch handler `[data-chapter-advance]`.

- [ ] **Step 1: Sisipkan `CHAPTER_STAGES` + handler modal**

Di `resources/views/manuscript/board.blade.php`, di dalam IIFE `(function () { … })();` pada `@push('scripts')`, tepat SEBELUM handler `document.addEventListener('click', function (e) { const btn = e.target.closest('[data-chapter-advance]'); … });`, tambahkan blok berikut:

```blade
    const CHAPTER_STAGES = @json(collect(\App\Models\TitleProgress::BOOK_STAGES)
        ->map(fn ($s) => ['value' => $s, 'label' => \App\Models\Title::stageLabel($s)])->values());

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-chapter-edit]');
        if (!btn) return;
        e.preventDefault();
        const cp      = btn.getAttribute('data-cp');
        const current = btn.getAttribute('data-current');
        const next    = btn.getAttribute('data-next');
        const judul   = btn.getAttribute('data-judul') || 'Bab';

        const options = CHAPTER_STAGES
            .filter((s) => s.value !== current)
            .map((s) => '<option value="' + s.value + '">' + s.label + '</option>')
            .join('');

        Swal.fire({
            title: 'Ubah tahap: ' + judul,
            html:
                '<select id="swal-stage" class="form-select form-select-sm mb-2">' + options + '</select>' +
                '<textarea id="swal-note" class="form-control form-control-sm" rows="2" placeholder="Catatan (wajib bila mundur/lompat)"></textarea>',
            showCancelButton: true,
            confirmButtonText: 'Simpan',
            cancelButtonText: 'Batal',
            focusConfirm: false,
            preConfirm: () => {
                const target = document.getElementById('swal-stage').value;
                const note   = document.getElementById('swal-note').value.trim();
                if (target !== next && note === '') {
                    Swal.showValidationMessage('Catatan wajib untuk lompat/mundur.');
                    return false;
                }
                return { target, note };
            },
        }).then((result) => {
            if (!result.isConfirmed) return;
            fetch(base + '/chapter/' + cp + '/advance', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ status: result.value.target, note: result.value.note }),
            })
            .then(async (res) => { const d = await res.json().catch(() => ({})); if (!res.ok) throw new Error(d.message || 'Gagal.'); toast(d.message || 'Bab diperbarui.', true); setTimeout(() => location.reload(), 500); })
            .catch((err) => toast(err.message, false));
        });
    });
```

- [ ] **Step 2: Verifikasi Blade tetap terkompilasi (kompilasi semua view)**

Run: `php artisan view:cache && php artisan view:clear`
Expected: `INFO  Blade templates cached successfully.` lalu `INFO  Compiled views cleared successfully.` (tanpa error kompilasi).

- [ ] **Step 3: Commit**

```bash
git add resources/views/manuscript/board.blade.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(chapter-jump): modal SweetAlert2 pilih tahap tujuan + catatan → POST chapter.advance

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 4: Verifikasi menyeluruh

**Files:** (tak ada perubahan kode; hanya verifikasi)

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test --env=testing`
Expected: semua PASS (baseline sebelumnya 377 + 4 test baru = 381 passed).

- [ ] **Step 2: Konfirmasi kompilasi view bersih**

Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

- [ ] **Step 3: (opsional) Cek manual di papan buku**

Buka papan manuskrip tipe buku, expand "📖 Bab", klik "Ubah…": modal muncul dengan dropdown tahap (tanpa tahap kini) + textarea; simpan tanpa catatan saat memilih tahap non-next → pesan validasi; simpan dengan catatan → toast sukses + reload; bab pindah tahap.

---

## Self-Review (penulis plan)

**1. Spec coverage:**
- Kriteria 1 (pindah ke tahap mana pun + catatan) → Task 2 (tombol) + Task 3 (modal). ✓
- Kriteria 2 (quick "Maju → next" tetap) → tak disentuh; tombol "Ubah…" ditambah di sampingnya. ✓
- Kriteria 3 (catatan wajib bila target≠next, UI+server) → Task 3 `preConfirm`; Task 1 `jump_without_note_is_rejected`. ✓
- Kriteria 4 (otorisasi production) → Task 1 `production_cannot_move_superadmin_stage_chapter`. ✓
- Kriteria 5 (`needs_review`) → Task 1 `manager_moves_chapter_backward_with_note` assert `needs_review`. ✓
- Kriteria 6 (suite hijau) → Task 4. ✓
- Spec §4 (`CHAPTER_STAGES` dari PHP) → Task 3. ✓ §3 (tombol data-*) → Task 2. ✓

**2. Placeholder scan:** Tak ada TBD/TODO; semua langkah berisi kode/pesan nyata.

**3. Type/nama konsistensi:** atribut `data-chapter-edit`, `data-cp`, `data-current`, `data-next`, `data-judul` konsisten antara Task 2 (Blade) & Task 3 (JS). Endpoint `chapter.advance` menerima `status` + `note` (dipakai konsisten di Task 1 & Task 3). `CHAPTER_STAGES` item `{value,label}` konsisten dipakai di filter/opsi.

Tak ada migrasi (tanpa perubahan skema). Test via `.env.testing`.
