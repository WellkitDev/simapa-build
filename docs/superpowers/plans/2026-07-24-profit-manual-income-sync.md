# Sinkron Pemasukan Manual ke Analisa Profit + Aksi Edit Jurnal Kas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Buat halaman Analisa Profit (`/accounting/profit`) menghitung pemasukan MANUAL Jurnal Kas (bukan hanya otomatis dari Payment), plus tambahkan aksi Edit + select2 tambah-inline (kategori/produk) di Jurnal Kas.

**Architecture:** Aditif — perhitungan Payment yang ada dipertahankan; pemasukan manual (`tb_cash_entries` `source≠payment`, `is_transfer=false`, `jenis=pemasukan`) ditambahkan di `ProfitAnalysisService::forMonth`. Margin manual: Buku→87%, Artikel→25% (S2), selain itu→100% Siap Dibagi. Kategori & produk jadi select2-tags; kategori baru → `firstOrCreate`. Aksi Edit reuse route/method `accounting.entry.update` yang sudah ada (backend siap), hanya menambah UI modal.

**Tech Stack:** Laravel (PHP), Blade, Bootstrap 5, select2 (`assets/plugins/select2`), inputmask (money-mask partial), PHPUnit. Test DB: `avidpedi_simapa_test` via `.env.testing` (`php artisan test`).

**Referensi:** spec `docs/superpowers/specs/2026-07-24-profit-manual-income-sync-design.md`. Margin seed (dari migrasi): `M_KOL_S2=25`, `M_KOL_S4=30`, `M_ART_S2=25`, `M_ART_S4=30`, `M_BK_ALL=87`.

---

## Catatan penting sebelum mulai

- Jalankan test dengan: `php artisan test` (memakai `.env.testing` → DB `avidpedi_simapa_test`, JANGAN DB asli). Filter satu file: `php artisan test --filter=ProfitAnalysisTest`.
- Margin (`CashMargin`) & kategori seed & role `accounting` DISEED oleh migrasi → tersedia otomatis di test (`RefreshDatabase`). Tak perlu diseed manual.
- `produk` = kolom `string` nullable (bukan enum) → aman menyimpan nilai bebas.
- Setelah semua selesai, ingat menjalankan `php artisan migrate` di DB dev bila ada migrasi baru — TAPI plan ini TIDAK menambah migrasi.

---

## Task 1: `ProfitAnalysisService` — sertakan pemasukan manual

**Files:**
- Modify: `app/Services/ProfitAnalysisService.php`
- Test: `tests/Feature/ProfitAnalysisTest.php`

- [ ] **Step 1: Tulis test yang gagal (margin manual per produk)**

Tambahkan method-method berikut ke `tests/Feature/ProfitAnalysisTest.php` (di dalam class, setelah test `payment_without_order_detail_is_counted_separately`). Helper `manualIncome` juga ditambahkan.

```php
    private function manualIncome(int $amount, ?string $produk, string $tgl = '2026-06-12', ?int $catId = null): \App\Models\CashEntry
    {
        return \App\Models\CashEntry::create([
            'tanggal' => $tgl, 'jenis' => 'pemasukan', 'amount' => $amount,
            'keterangan' => 'Manual ' . ($produk ?? 'x'), 'produk' => $produk,
            'cash_category_id' => $catId, 'source' => 'manual', 'is_transfer' => false,
        ]);
    }

    /** @test */
    public function manual_income_buku_splits_by_book_margin(): void
    {
        $this->manualIncome(1_000_000, 'buku');

        $h = $this->juni();

        $this->assertSame(1_000_000.0, $h['totalIn']);
        $this->assertSame(870_000.0, $h['totalMargin'], '87% x 1jt.');
        $this->assertSame(130_000.0, $h['totalReserve']);
    }

    /** @test */
    public function manual_income_artikel_uses_lowest_25_percent(): void
    {
        $this->manualIncome(1_000_000, 'artikel');

        $h = $this->juni();

        $this->assertSame(250_000.0, $h['totalMargin'], '25% (S2 terendah) x 1jt.');
        $this->assertSame(750_000.0, $h['totalReserve']);
    }

    /** @test */
    public function manual_income_non_product_is_fully_distributable(): void
    {
        $this->manualIncome(1_000_000, 'operasional');
        $this->manualIncome(500_000, null);
        $this->manualIncome(300_000, 'jasa editing'); // produk kustom

        $h = $this->juni();

        $this->assertSame(1_800_000.0, $h['totalIn']);
        $this->assertSame(1_800_000.0, $h['totalMargin'], 'Semua 100% siap dibagi.');
        $this->assertSame(0.0, $h['totalReserve']);
    }

    /** @test */
    public function manual_and_payment_income_combine_without_double_count(): void
    {
        // Payment otomatis: at_kolab sinta2 1,5jt → margin 375rb.
        $this->pay($this->order('at_kolab', 'sinta 2'), 1_500_000);
        // Manual buku 1jt → margin 870rb.
        $this->manualIncome(1_000_000, 'buku');

        $h = $this->juni();

        $this->assertSame(2_500_000.0, $h['totalIn'], '1,5jt + 1jt, tanpa dobel.');
        $this->assertSame(1_245_000.0, $h['totalMargin'], '375rb + 870rb.');
    }

    /** @test */
    public function transfers_and_manual_expenses_are_excluded_from_profit(): void
    {
        // Pengeluaran manual — bukan pemasukan.
        \App\Models\CashEntry::create(['tanggal' => '2026-06-12', 'jenis' => 'pengeluaran', 'amount' => 999_000, 'keterangan' => 'Biaya', 'source' => 'manual', 'is_transfer' => false]);
        // Sisi masuk sebuah transfer — internal, bukan pemasukan riil.
        \App\Models\CashEntry::create(['tanggal' => '2026-06-12', 'jenis' => 'pemasukan', 'amount' => 999_000, 'keterangan' => 'Transfer masuk', 'source' => 'manual', 'is_transfer' => true]);

        $h = $this->juni();

        $this->assertSame(0.0, $h['totalIn']);
        $this->assertSame(0.0, $h['totalMargin']);
    }

    /** @test */
    public function yearly_includes_manual_income(): void
    {
        $this->manualIncome(1_000_000, 'buku', '2026-05-12'); // Mei
        $this->manualIncome(1_000_000, 'buku', '2026-06-12'); // Juni

        $tahun = app(ProfitAnalysisService::class)->yearly(2026);

        $this->assertSame(870_000.0, $tahun[4]['totalMargin'], 'Mei (index 4).');
        $this->assertSame(870_000.0, $tahun[5]['totalMargin'], 'Juni (index 5).');
    }
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=ProfitAnalysisTest`
Expected: FAIL — pemasukan manual belum dihitung (mis. `manual_income_buku_splits_by_book_margin` → `totalIn` = 0, bukan 1jt).

- [ ] **Step 3: Implementasi — tambah `marginForManual` + loop manual di `forMonth`**

Di `app/Services/ProfitAnalysisService.php`:

(a) Tambahkan import di atas (sudah ada `Payment`, `OrderDetail`, `CashMargin`):

```php
use App\Models\CashEntry;
```

(b) Tambahkan method baru `marginForManual` (mis. tepat setelah method `marginFor`):

```php
    /**
     * Margin untuk pemasukan MANUAL (tanpa order). Produk menentukan:
     * buku → M_BK_ALL; artikel → S2 terendah (tak ada indeksasi); selain itu → 100% siap dibagi.
     * Kategori dgn map_key dipakai bila produk kosong.
     *
     * @return array{code:?string,pct:float,unknownTier:bool,marginMissing:bool}
     */
    public function marginForManual(CashEntry $e): array
    {
        $produk = strtolower(trim((string) $e->produk));
        $mapKey = optional($e->category)->map_key;

        $code = null;
        if ($produk === 'buku') {
            $code = 'M_BK_ALL';
        } elseif ($produk === 'artikel') {
            $code = $mapKey === 'at_mandiri' ? 'M_ART_S2' : 'M_KOL_S2';
        } elseif (in_array($mapKey, ['bk_kolab', 'bk_mandiri'], true)) {
            $code = 'M_BK_ALL';
        } elseif ($mapKey === 'at_mandiri') {
            $code = 'M_ART_S2';
        } elseif ($mapKey === 'at_kolab') {
            $code = 'M_KOL_S2';
        }

        if ($code === null) {
            // Non-artikel/buku → seluruhnya siap dibagi, tanpa cadangan APC.
            return ['code' => null, 'pct' => 100.0, 'unknownTier' => false, 'marginMissing' => false];
        }

        $margin = CashMargin::where('code', $code)->where('active', true)->first();

        return [
            'code'          => $code,
            'pct'           => (float) ($margin->margin_pct ?? 0),
            'unknownTier'   => false,
            'marginMissing' => $margin === null,
        ];
    }
```

(c) Di `forMonth`, tambahkan `'manual' => false` pada baris payment yang sudah ada (agar view punya key seragam), lalu tambahkan loop manual SEBELUM `return compact(...)`. Ubah blok akhir method `forMonth` menjadi:

Cari baris array payment `$rows[] = [ ... 'marginMissing' => $m['marginMissing'], ];` dan tambahkan satu baris `'manual' => false,` sebelum penutup `];`.

Kemudian, tepat sebelum `return compact('rows', ...)`, sisipkan:

```php
        $manual = CashEntry::where('jenis', 'pemasukan')
            ->where('source', '!=', 'payment')
            ->where('is_transfer', false)
            ->whereYear('tanggal', $year)->whereMonth('tanggal', $month)
            ->with('category')
            ->orderBy('tanggal')->orderBy('id')->get();

        foreach ($manual as $e) {
            $m    = $this->marginForManual($e);
            $base = (float) $e->amount;
            $marg = round($base * $m['pct'] / 100, 2);
            $res  = $base - $marg;

            $totalIn      += $base;
            $totalMargin  += $marg;
            $totalReserve += $res;

            $rows[] = [
                'tanggal'       => optional($e->tanggal)->format('d/m/y'),
                'code_order'    => null,
                'judul'         => $e->keterangan,
                'type'          => $e->produk ?: '(manual)',
                'indexation'    => null,
                'marginCode'    => $m['code'],
                'pct'           => $m['pct'],
                'base'          => $base,
                'reserve'       => $res,
                'margin'        => $marg,
                'unknownTier'   => false,
                'marginMissing' => $m['marginMissing'],
                'manual'        => true,
            ];
        }
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter=ProfitAnalysisTest`
Expected: PASS (semua test lama + 6 test baru hijau).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ProfitAnalysisService.php tests/Feature/ProfitAnalysisTest.php
git -c user.name=WellkitDev -c user.email=rahmatpurnomo808@gmail.com commit -m "feat(keuangan): pemasukan manual ikut dihitung di Analisa Profit

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 2: Tampilan Analisa Profit — tandai baris manual + perbarui teks

**Files:**
- Modify: `resources/views/accounting/profit-analysis.blade.php`

- [ ] **Step 1: Tandai baris manual di tabel Rincian**

Ganti kolom "Order" pada loop `$rows` (baris `<td>{{ $r['code_order'] ?? '—' }}</td>`) menjadi:

```blade
                        <td>
                            {{ $r['code_order'] ?? '—' }}
                            @if($r['manual'] ?? false)<span class="badge bg-secondary" title="Pemasukan input manual di Jurnal Kas">manual</span>@endif
                        </td>
```

- [ ] **Step 2: Perbarui paragraf penjelas**

Ganti paragraf `<p class="text-muted small mb-3"> ... Pemasukan non-order tidak dihitung karena tak punya margin.</p>` menjadi:

```blade
    <p class="text-muted small mb-3">
        Angka ini berbasis <strong>margin asumsi</strong> (<a href="{{ route('accounting.assumption') }}">Asumsi &rarr; Margin per Produk</a>), bukan biaya APC yang sesungguhnya —
        sistem belum bisa menautkan pengeluaran ke order tertentu. <strong>Pemasukan manual</strong> di Jurnal Kas ikut dihitung: produk Artikel/Buku dibagi sesuai margin, selain itu 100% siap dibagi.
    </p>
```

- [ ] **Step 3: Verifikasi render (test feature yang sudah ada)**

Run: `php artisan test --filter=ProfitAnalysisTest`
Expected: PASS (`page_renders_and_links_to_distribution` tetap hijau — perubahan hanya menambah markup opsional).

- [ ] **Step 4: Commit**

```bash
git add resources/views/accounting/profit-analysis.blade.php
git -c user.name=WellkitDev -c user.email=rahmatpurnomo808@gmail.com commit -m "feat(keuangan): tandai baris pemasukan manual di Analisa Profit

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 3: `CashEntryController` — resolver kategori inline + validasi produk bebas

**Files:**
- Modify: `app/Http/Controllers/Pages/CashEntryController.php`
- Test: `tests/Feature/AccountingJournalTest.php`

- [ ] **Step 1: Tulis test yang gagal (kategori baru + produk kustom)**

Tambahkan ke `tests/Feature/AccountingJournalTest.php` (dalam class):

```php
    /** @test */
    public function store_creates_new_category_inline_from_name(): void
    {
        $this->actingAs($this->user('accounting'))->post(route('accounting.entry.store'), [
            'tanggal' => '2026-06-05', 'jenis' => 'pemasukan',
            'cash_category_id' => 'Konsultasi', // nama baru, bukan id
            'amount' => 400000, 'produk' => 'jasa editing', 'keterangan' => 'Fee konsultasi',
        ])->assertRedirect();

        $cat = CashCategory::where('name', 'Konsultasi')->where('jenis', 'pemasukan')->first();
        $this->assertNotNull($cat, 'Kategori baru dibuat inline.');

        $e = CashEntry::where('keterangan', 'Fee konsultasi')->first();
        $this->assertSame($cat->id, $e->cash_category_id);
        $this->assertSame('jasa editing', $e->produk, 'Produk kustom tersimpan apa adanya.');
    }

    /** @test */
    public function store_reuses_existing_category_when_id_given(): void
    {
        $cat = CashCategory::where('jenis', 'pemasukan')->first();
        $before = CashCategory::count();

        $this->actingAs($this->user('accounting'))->post(route('accounting.entry.store'), [
            'tanggal' => '2026-06-05', 'jenis' => 'pemasukan',
            'cash_category_id' => (string) $cat->id, 'amount' => 100000, 'keterangan' => 'Pakai kategori ada',
        ])->assertRedirect();

        $this->assertSame($before, CashCategory::count(), 'Tak membuat kategori baru untuk id yang ada.');
        $this->assertSame($cat->id, CashEntry::where('keterangan', 'Pakai kategori ada')->first()->cash_category_id);
    }
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=AccountingJournalTest`
Expected: FAIL — `store_creates_new_category_inline_from_name` gagal karena validasi `cash_category_id` lama (`exists`) menolak string "Konsultasi".

- [ ] **Step 3: Implementasi — resolver kategori + longgarkan validasi produk**

Di `app/Http/Controllers/Pages/CashEntryController.php`:

(a) Ubah method `validated()` — ganti aturan `cash_category_id` dan `produk`:

```php
    private function validated(Request $request): array
    {
        return $request->validate([
            'tanggal'          => 'required|date',
            'jenis'            => 'required|in:pemasukan,pengeluaran',
            'cash_category_id' => 'nullable|string|max:100',
            'account_id'       => 'nullable|exists:tb_cash_accounts,id',
            'amount'           => 'required|numeric|min:0',
            'produk'           => 'nullable|string|max:50',
            'keterangan'       => 'required|string|max:255',
            'ref'              => 'nullable|string|max:100',
            'catatan'          => 'nullable|string',
        ]);
    }
```

(b) Tambahkan helper resolver (mis. tepat setelah `validated()`):

```php
    /** id kategori: numerik & ada → id; string nama → firstOrCreate (nama+jenis); kosong → null. */
    private function resolveCategoryId($raw, string $jenis): ?int
    {
        $raw = is_string($raw) ? trim($raw) : $raw;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw) && ($cat = CashCategory::find((int) $raw))) {
            return $cat->id;
        }

        return CashCategory::firstOrCreate(
            ['name' => (string) $raw, 'jenis' => $jenis],
            ['active' => true, 'position' => (int) CashCategory::where('jenis', $jenis)->max('position') + 1]
        )->id;
    }
```

(c) Di `store()`, setelah `$data = $this->validated($request);` dan sebelum `CashEntry::create($data);`, sisipkan resolusi kategori:

```php
        $data['cash_category_id'] = $this->resolveCategoryId($data['cash_category_id'] ?? null, $data['jenis']);
```

(d) Di `update()`, setelah `$data = $this->validated($request);`, sisipkan baris yang sama:

```php
        $data['cash_category_id'] = $this->resolveCategoryId($data['cash_category_id'] ?? null, $data['jenis']);
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter=AccountingJournalTest`
Expected: PASS (2 test baru + semua test lama hijau; `accounting_and_superadmin_can_store_entry` yang kirim id numerik tetap jalan).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Pages/CashEntryController.php tests/Feature/AccountingJournalTest.php
git -c user.name=WellkitDev -c user.email=rahmatpurnomo808@gmail.com commit -m "feat(keuangan): kategori bisa dibuat inline + produk bebas di entri kas

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 4: `CashEntry::produkLabel()` + tampilan produk kustom

**Files:**
- Modify: `app/Models/CashEntry.php`
- Modify: `resources/views/accounting/journal.blade.php` (kolom Produk tabel)
- Modify: `app/Http/Controllers/Pages/CashEntryController.php` (kolom Produk CSV)

- [ ] **Step 1: Tambah method `produkLabel()` di model**

Di `app/Models/CashEntry.php`, tambahkan setelah `isPemasukan()`:

```php
    /** Label produk: dari konstanta bila baku, else teksnya sendiri (produk kustom), else '—'. */
    public function produkLabel(): string
    {
        return self::PRODUK[$this->produk] ?? ($this->produk ? ucfirst($this->produk) : '—');
    }
```

- [ ] **Step 2: Pakai `produkLabel()` di tabel Jurnal Kas**

Di `resources/views/accounting/journal.blade.php`, ganti sel produk pada loop entri:

Dari:
```blade
                        <td>{{ \App\Models\CashEntry::PRODUK[$e->produk] ?? '—' }}</td>
```
Menjadi:
```blade
                        <td>{{ $e->produkLabel() }}</td>
```

- [ ] **Step 3: Pakai produk kustom di export CSV**

Di `app/Http/Controllers/Pages/CashEntryController.php` method `exportCsv`, ganti baris produk:

Dari:
```php
                    \App\Models\CashEntry::PRODUK[$e->produk] ?? '',
```
Menjadi:
```php
                    \App\Models\CashEntry::PRODUK[$e->produk] ?? ($e->produk ?: ''),
```

- [ ] **Step 4: Jalankan test terkait**

Run: `php artisan test --filter=AccountingJournalTest`
Expected: PASS (tanpa regresi).

- [ ] **Step 5: Commit**

```bash
git add app/Models/CashEntry.php resources/views/accounting/journal.blade.php app/Http/Controllers/Pages/CashEntryController.php
git -c user.name=WellkitDev -c user.email=rahmatpurnomo808@gmail.com commit -m "feat(keuangan): tampilkan produk kustom di Jurnal Kas (label + CSV)

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 5: Jurnal Kas UI — select2-tags (form tambah) + modal Edit

**Files:**
- Modify: `resources/views/accounting/journal.blade.php`

Ini murni tampilan (backend sudah teruji di Task 3). Verifikasi manual di browser; tak ada test PHPUnit baru untuk JS.

- [ ] **Step 1: Muat aset select2**

Di blok `@push('plugin-styles')` (paling atas file, sudah berisi datatables), tambahkan sebelum `@endpush`:

```blade
<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
```

Di blok `@push('plugin-scripts')` (dekat bawah, sudah berisi datatables js), tambahkan sebelum `@endpush`:

```blade
<script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
```

- [ ] **Step 2: Jadikan kategori & produk select2-tags di form Tambah**

Di form `#entryForm`, pada `<select name="cash_category_id" ...>` tambahkan class `select2-tags`, dan pada `<select name="produk" ...>` tambahkan class `select2-tags`. Hasilnya:

```blade
                <div class="col-md-3"><label class="form-label small mb-1">Kategori</label>
                    <select name="cash_category_id" class="form-select form-select-sm select2-tags" data-placeholder="Pilih / ketik kategori baru">
                        <option value="">—</option>
                        @foreach($categories as $c)<option value="{{ $c->id }}" data-jenis="{{ $c->jenis }}">{{ $c->name }} ({{ \App\Models\CashCategory::JENIS[$c->jenis] }})</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small mb-1">Nominal</label><input type="text" name="amount" class="form-control form-control-sm money-mask" inputmode="numeric" min="0" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">Produk</label>
                    <select name="produk" class="form-select form-select-sm select2-tags" data-placeholder="Pilih / ketik produk">
                        <option value="">—</option>
                        @foreach(\App\Models\CashEntry::PRODUK as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                    </select>
                </div>
```

- [ ] **Step 3: Tambah tombol Edit di kolom Aksi (hanya entri manual non-transfer)**

Di loop `@foreach($entries as $e)`, kolom Aksi, pada cabang `@else` (entri manual biasa — BUKAN payment, BUKAN transfer), ganti isinya agar ada tombol Edit di samping Hapus:

Dari:
```blade
                            @else
                                @can('accounting.journal.delete')
                                <form method="POST" action="{{ route('accounting.entry.destroy', $e->id) }}" data-confirm="Hapus transaksi ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                                @endcan
                            @endif
```
Menjadi:
```blade
                            @else
                                <div class="d-flex gap-1">
                                @can('accounting.journal.edit')
                                <button type="button" class="btn btn-xs btn-outline-primary"
                                    data-edit-entry
                                    data-action="{{ route('accounting.entry.update', $e->id) }}"
                                    data-tanggal="{{ optional($e->tanggal)->format('Y-m-d') }}"
                                    data-jenis="{{ $e->jenis }}"
                                    data-account="{{ $e->account_id }}"
                                    data-category="{{ $e->cash_category_id }}"
                                    data-produk="{{ $e->produk }}"
                                    data-amount="{{ (int) $e->amount }}"
                                    data-keterangan="{{ $e->keterangan }}"
                                    data-ref="{{ $e->ref }}"
                                    data-catatan="{{ $e->catatan }}">✎</button>
                                @endcan
                                @can('accounting.journal.delete')
                                <form method="POST" action="{{ route('accounting.entry.destroy', $e->id) }}" data-confirm="Hapus transaksi ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                                @endcan
                                </div>
                            @endif
```

> Catatan: permission `accounting.journal.edit` sudah dipakai oleh route `accounting.entry.update` (peta permission). Bila di lingkungan Anda nama izinnya berbeda, samakan dengan yang route pakai. Verifikasi cepat: `config/permissions.php` / cara route lain memakainya.

- [ ] **Step 4: Tambah modal Edit sebelum `@endsection`**

Sisipkan markup modal ini tepat sebelum baris `@include('accounting.partials.money-mask')` (yang berada sebelum `@endsection`):

```blade
{{-- Modal Edit Transaksi (hanya entri manual non-transfer) --}}
@can('accounting.journal.edit')
<div class="modal fade" id="editEntryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="editEntryForm">
        @csrf
        @method('PUT')
        <div class="modal-header">
          <h6 class="modal-title">Edit Transaksi Kas</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-3"><label class="form-label small mb-1">Tanggal</label><input type="date" name="tanggal" class="form-control form-control-sm" required></div>
            <div class="col-md-3"><label class="form-label small mb-1">Jenis</label><select name="jenis" class="form-select form-select-sm"><option value="pemasukan">Pemasukan</option><option value="pengeluaran">Pengeluaran</option></select></div>
            <div class="col-md-3"><label class="form-label small mb-1">Akun</label>
              <select name="account_id" class="form-select form-select-sm">@foreach($allAccounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
            </div>
            <div class="col-md-3"><label class="form-label small mb-1">Kategori</label>
              <select name="cash_category_id" class="form-select form-select-sm select2-tags" data-placeholder="Pilih / ketik kategori">
                <option value="">—</option>
                @foreach($allCategories as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ \App\Models\CashCategory::JENIS[$c->jenis] }})</option>@endforeach
              </select>
            </div>
            <div class="col-md-3"><label class="form-label small mb-1">Nominal</label><input type="text" name="amount" class="form-control form-control-sm money-mask" inputmode="numeric" min="0" required></div>
            <div class="col-md-3"><label class="form-label small mb-1">Produk</label>
              <select name="produk" class="form-select form-select-sm select2-tags" data-placeholder="Pilih / ketik produk">
                <option value="">—</option>
                @foreach(\App\Models\CashEntry::PRODUK as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
              </select>
            </div>
            <div class="col-md-6"><label class="form-label small mb-1">Keterangan</label><input name="keterangan" class="form-control form-control-sm" required></div>
            <div class="col-md-4"><label class="form-label small mb-1">Ref (INV/Order)</label><input name="ref" class="form-control form-control-sm"></div>
            <div class="col-md-8"><label class="form-label small mb-1">Catatan</label><input name="catatan" class="form-control form-control-sm"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-sm btn-primary">Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endcan
```

- [ ] **Step 5: Tambah JS init select2 + isi modal**

Di blok `@push('custom-scripts')` (paling bawah file, yang saat ini hanya init DataTable), tambahkan setelah baris DataTable — ganti isi blok menjadi:

```blade
@push('custom-scripts')
<script>
$(function () {
    $('.datatable').DataTable({ pageLength: 25, responsive: true, order: [], language: { emptyTable: 'Belum ada transaksi.' } });

    var hasSelect2 = window.jQuery && jQuery.fn.select2;
    if (hasSelect2) {
        // Form tambah (di luar modal)
        $('.select2-tags').not('#editEntryModal .select2-tags').select2({ tags: true, width: '100%' });
        // Modal edit — dropdownParent supaya tak terpotong
        $('#editEntryModal .select2-tags').select2({ tags: true, width: '100%', dropdownParent: $('#editEntryModal') });
    }

    function setTagVal($sel, val) {
        val = (val === undefined || val === null) ? '' : String(val);
        if (val !== '' && $sel.find('option').filter(function () { return this.value === val; }).length === 0) {
            $sel.append(new Option(val, val, true, true));
        }
        $sel.val(val);
        if (hasSelect2) { $sel.trigger('change'); }
    }

    $('[data-edit-entry]').on('click', function () {
        var b = $(this);
        var $f = $('#editEntryForm');
        $f.attr('action', b.attr('data-action'));
        $f.find('[name=tanggal]').val(b.attr('data-tanggal'));
        $f.find('[name=jenis]').val(b.attr('data-jenis'));
        setTagVal($f.find('[name=account_id]'), b.attr('data-account'));
        setTagVal($f.find('[name=cash_category_id]'), b.attr('data-category'));
        setTagVal($f.find('[name=produk]'), b.attr('data-produk'));
        $f.find('[name=amount]').val(b.attr('data-amount'));
        $f.find('[name=keterangan]').val(b.attr('data-keterangan'));
        $f.find('[name=ref]').val(b.attr('data-ref'));
        $f.find('[name=catatan]').val(b.attr('data-catatan'));
        var modalEl = document.getElementById('editEntryModal');
        (bootstrap.Modal.getOrCreateInstance ? bootstrap.Modal.getOrCreateInstance(modalEl) : new bootstrap.Modal(modalEl)).show();
    });
});
</script>
@endpush
```

> Catatan: `account_id` bukan tag sebenarnya (nilai akun tetap), tapi `setTagVal` aman dipakai untuk memilih option yang sudah ada. Money-mask sudah otomatis terpasang ke `.money-mask` di modal (partial di-include di halaman ini dan modal ada di DOM saat load).

- [ ] **Step 6: Verifikasi manual di browser**

Jalankan app (`php artisan serve` atau XAMPP) → buka `/accounting/journal`:
1. Klik "+ Tambah Transaksi" → Kategori & Produk kini select2; ketik kategori baru "Konsultasi" → simpan → entri muncul, kategori baru terbentuk.
2. Ketik produk baru "jasa editing" → simpan → tampil "jasa editing" di kolom Produk.
3. Pada entri manual, klik tombol ✎ → modal terisi nilai lama → ubah nominal/keterangan → Simpan Perubahan → nilai ter-update.
4. Entri "⚙ auto" (dari payment) TIDAK punya tombol ✎. Transfer TIDAK punya ✎ (hanya ×).
5. Buka `/accounting/profit` → pemasukan manual tampil di "Rincian per Pembayaran" dengan badge "manual"; angka "Siap Dibagi" bertambah sesuai aturan (buku 87% / artikel 25% / lainnya 100%).

- [ ] **Step 7: Commit**

```bash
git add resources/views/accounting/journal.blade.php
git -c user.name=WellkitDev -c user.email=rahmatpurnomo808@gmail.com commit -m "feat(keuangan): select2 tambah-inline + aksi Edit (modal) di Jurnal Kas

Co-Authored-By: Mira <admin@avidpedia.com>"
```

---

## Task 6: Regresi penuh + tutup

**Files:** — (tak ada perubahan kode; verifikasi)

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua (baseline sebelumnya 593 hijau + test baru; tak ada yang merah).

- [ ] **Step 2: Bila ada yang merah**

Gunakan skill `superpowers:systematic-debugging` — jangan tebak. Perbaiki akar masalah, jalankan ulang.

- [ ] **Step 3: Update memory keuangan**

Tambahkan catatan singkat ke memory `keuangan-state-dan-lanjutan` (via file di direktori memory): "Analisa Profit kini juga menghitung pemasukan MANUAL Jurnal Kas (buku 87% / artikel 25% S2 / lainnya 100%); kategori+produk select2 tambah-inline; entri manual non-transfer bisa di-edit." Perbarui pointer di `MEMORY.md` bila perlu.

- [ ] **Step 4: Finalisasi branch**

Gunakan skill `superpowers:finishing-a-development-branch` untuk memutuskan merge ke `main` (pola repo: merge lokal, push bila diminta).

---

## Self-review (diisi saat menulis plan)

- **Cakupan spec:** Bagian 1 (margin manual + include) → Task 1. Tanda baris + teks → Task 2. select2 tambah-inline kategori/produk (Bagian 2) → Task 3 (backend) + Task 5 (UI). Aksi Edit (Bagian 3) → Task 5 (UI; backend sudah ada). Tampilan produk kustom → Task 4. Test spec → Task 1 & 3. Semua tercakup.
- **Placeholder:** tidak ada TBD/TODO; setiap step berisi kode nyata.
- **Konsistensi tipe:** `marginForManual()` mengembalikan bentuk array yang sama dengan `marginFor()` (`code/pct/unknownTier/marginMissing`); baris `$rows` manual punya semua key yang dibaca view + `manual`. `resolveCategoryId()` dipanggil di store & update dengan tanda tangan sama. Nama route/permission (`accounting.entry.update`, `accounting.journal.edit`) konsisten dengan yang dipakai route.
- **Di luar scope (sengaja, sesuai spec):** unifikasi penuh sumber pemasukan, pengeluaran manual ke profit, edit transfer/auto, filter akun di profit.
