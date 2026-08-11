@extends('layouts.master')
@section('title', ($mode === 'create' ? 'Buat' : 'Edit') . ' Invoice Layanan - SiMAPA')

@section('content')
<form method="POST"
      action="{{ $mode === 'create' ? route('service.invoice.store') : route('service.invoice.update', $invoice->id) }}">
    @csrf
    @if($mode === 'edit') @method('PUT') @endif

    {{-- WAJIB: layouts/master hanya merender session success/error/info, BUKAN $errors.
         Tanpa blok ini, kegagalan validasi pada baris item (yang dirender lewat JS,
         bukan @error per-baris) memantul ke form ini tanpa satu pun tanda terlihat. --}}
    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>Data belum tersimpan.</strong>
            <ul class="mb-0 mt-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row">
        <div class="col-md-5 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Data Klien</h6>

                    <div class="mb-2">
                        <label class="form-label">Pilih Klien Terdaftar</label>
                        <select id="clientPicker" class="form-select" onchange="applyClient()">
                            <option value="">— Klien baru (isi manual di bawah) —</option>
                            @foreach($clients as $c)
                                {{-- Payload dirakit eksplisit, bukan $c->toJson(): applyClient() hanya
                                     memakai enam kolom ini — toJson() ikut mengirim note/created_by/
                                     timestamps internal klien ke browser tanpa alasan. --}}
                                <option value="{{ $c->id }}"
                                    data-client="{{ json_encode([
                                        'id'          => $c->id,
                                        'name'        => $c->name,
                                        'institution' => $c->institution,
                                        'email'       => $c->email,
                                        'phone'       => $c->phone,
                                        'address'     => $c->address,
                                    ]) }}"
                                    {{ old('service_client_id', $invoice->service_client_id ?? '') == $c->id ? 'selected' : '' }}>
                                    {{ $c->displayName() }}
                                </option>
                            @endforeach
                        </select>
                        <input type="hidden" name="service_client_id" id="serviceClientId"
                               value="{{ old('service_client_id', $invoice->service_client_id ?? '') }}">
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Nama <span class="text-danger">*</span></label>
                        <input type="text" name="client_name" id="clientName" class="form-control @error('client_name') is-invalid @enderror"
                               value="{{ old('client_name', $invoice->client_name ?? '') }}" required maxlength="190">
                        @error('client_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Instansi</label>
                        <input type="text" name="client_institution" id="clientInstitution" class="form-control"
                               value="{{ old('client_institution', $invoice->client_institution ?? '') }}" maxlength="190">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Email <small class="text-muted">(wajib bila mau dikirim email)</small></label>
                        <input type="email" name="client_email" id="clientEmail" class="form-control"
                               value="{{ old('client_email', $invoice->client_email ?? '') }}" maxlength="190">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Telepon</label>
                        <input type="text" name="client_phone" id="clientPhone" class="form-control"
                               value="{{ old('client_phone', $invoice->client_phone ?? '') }}" maxlength="40">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Alamat</label>
                        <textarea name="client_address" id="clientAddress" class="form-control" rows="2">{{ old('client_address', $invoice->client_address ?? '') }}</textarea>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-6 mb-2">
                            <label class="form-label">Tanggal Terbit <span class="text-danger">*</span></label>
                            <input type="date" name="issued_at" class="form-control @error('issued_at') is-invalid @enderror"
                                   value="{{ old('issued_at', isset($invoice) ? $invoice->issued_at?->format('Y-m-d') : now()->format('Y-m-d')) }}" required>
                            @error('issued_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label">Jatuh Tempo</label>
                            <input type="date" name="due_at" class="form-control @error('due_at') is-invalid @enderror"
                                   value="{{ old('due_at', isset($invoice) ? $invoice->due_at?->format('Y-m-d') : now()->addDays(14)->format('Y-m-d')) }}">
                            @error('due_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Catatan <small class="text-muted">(tercetak di PDF)</small></label>
                        <textarea name="note" class="form-control" rows="2">{{ old('note', $invoice->note ?? '') }}</textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Catatan Internal <small class="text-muted">(tidak tercetak)</small></label>
                        <textarea name="internal_note" class="form-control" rows="2">{{ old('internal_note', $invoice->internal_note ?? '') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-7 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Rincian Layanan</h6>

                    @error('items') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                    <div class="mb-2">
                        <label class="form-label">Ambil dari Katalog</label>
                        <select id="catalogPicker" class="form-select" onchange="addFromCatalog()">
                            <option value="">— Pilih layanan —</option>
                            @foreach($catalogs->groupBy('category') as $category => $rows)
                                <optgroup label="{{ \App\Models\ServiceCatalog::categoryLabel($category) }}">
                                    @foreach($rows as $cat)
                                        <option value="{{ $cat->id }}"
                                                data-name="{{ $cat->name }}"
                                                data-price="{{ (int) $cat->price }}">
                                            {{ $cat->name }} — {{ $cat->priceLabel() }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <small class="text-muted">Harga terisi otomatis dan tetap bisa diubah sesuai kompleksitas.</small>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th style="width:40%">Layanan</th><th style="width:12%">Qty</th>
                                    <th style="width:22%">Harga</th><th style="width:20%">Subtotal</th><th></th>
                                </tr>
                            </thead>
                            <tbody id="itemRows"></tbody>
                        </table>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addRow()">+ Tambah Baris</button>

                    <hr>
                    <div class="row">
                        <div class="col-6">
                            <label class="form-label">Diskon (Rp)</label>
                            <input type="text" name="discount" id="discount" class="form-control @error('discount') is-invalid @enderror"
                                   value="{{ old('discount', isset($invoice) ? (int) $invoice->discount : 0) }}" oninput="recalc()">
                            @error('discount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-6 text-end">
                            <div class="text-muted small">Subtotal</div>
                            <div class="h6" id="previewSubtotal">Rp 0</div>
                            <div class="text-muted small">Total</div>
                            <div class="h5 text-primary" id="previewTotal">Rp 0</div>
                        </div>
                    </div>

                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Simpan</button>
                        <a href="{{ $mode === 'create' ? route('service.invoice.index') : route('service.invoice.show', $invoice->id) }}"
                           class="btn btn-outline-secondary">Batal</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('custom-scripts')
<script>
    // Angka di layar ini hanya PRATINJAU. Nilai yang disimpan selalu dihitung ulang
    // di server dari qty & unit_price mentah (ServiceInvoiceForm::syncItems).
    let rowIndex = 0;

    {{--
        Perakitan dipindah ke blok PHP terpisah (bukan langsung di dalam pemanggilan
        json(...) di JS di bawah) karena kombinasi old(...) + ternary isset(...) +
        closure fn ($i) => [...] membuat pemroses arahan Blade (penghitung tanda
        kurung berbasis token) berhenti pada ')' yang salah dan memotong array di
        tengah — terverifikasi lewat compileString() di luar app: hasil kompilasinya
        terpotong jadi "json_encode($invoice->items->map(fn ($i) => ['service_catalog_id'
        => ..., 'name' => ...)" (tanda kurung siku dan sisa key tak pernah ikut), yang
        meledak jadi ParseError saat view dicompile. Memanggilnya atas satu variabel
        tunggal tidak kena masalah ini.

        CATATAN UNTUK PENYUNTING SELANJUTNYA: komentar Blade ini SENGAJA menghindari
        menulis kata "at-php" atau "at-json" apa adanya. Blade mencari pasangan blok
        php mentah dengan regex naif SEBELUM komentar dibuang, jadi kata itu di sini
        akan dikawinkan dengan penutup blok php nyata di bawah dan meledak lagi persis
        seperti bug yang baru saja diperbaiki.
    --}}
    @php
        // array_values WAJIB: removeRow() tidak menomori ulang, jadi menghapus baris
        // di tengah menyisakan kunci berlubang ([1,2]) dan json_encode memancarkannya
        // sebagai OBJEK, bukan larik. `existingItems.length` jadi undefined, cabang
        // else berjalan, dan seluruh baris yang sudah diketik operator lenyap saat
        // form memantul karena galat validasi.
        $existingItemsForJs = array_values((array) old('items', isset($invoice)
            ? $invoice->items->map(fn ($i) => [
                'service_catalog_id' => $i->service_catalog_id,
                'name'               => $i->name,
                'description'        => $i->description,
                'qty'                => (float) $i->qty,
                'unit_price'         => (int) $i->unit_price,
              ])->values()->all()
            : []));
    @endphp
    const existingItems = @json($existingItemsForJs);

    function rupiah(n) {
        return 'Rp ' + (Math.round(n) || 0).toLocaleString('id-ID');
    }

    function digits(v) {
        return parseFloat(String(v).replace(/[.,\s]/g, '')) || 0;
    }

    function addRow(item = {}) {
        const i = rowIndex++;

        // Dibangun lewat DOM, BUKAN insertAdjacentHTML dengan interpolasi. Nilai yang
        // masuk ke sini berasal dari old() dan — sejak Task 9 — dari basis data, jadi
        // menyisipkannya sebagai HTML membuat nama layanan bisa membobol atribut
        // value="" dan mengeksekusi skrip di browser operator lain. `.value =` menugaskan
        // properti string dan tidak pernah mengurai markup.
        const field = (attrs) => Object.assign(document.createElement('input'), attrs);

        const catalogId = field({ type: 'hidden', name: `items[${i}][service_catalog_id]`, value: item.service_catalog_id ?? '' });
        const description = field({ type: 'hidden', name: `items[${i}][description]`, value: item.description ?? '' });
        const name = field({ type: 'text', name: `items[${i}][name]`, className: 'form-control form-control-sm', value: item.name ?? '', required: true, maxLength: 190 });

        const qty = field({ type: 'number', step: '0.01', min: '0.01', name: `items[${i}][qty]`, className: 'form-control form-control-sm qty', value: item.qty ?? 1, required: true });
        const price = field({ type: 'text', name: `items[${i}][unit_price]`, className: 'form-control form-control-sm price', value: item.unit_price ?? 0, required: true });
        qty.addEventListener('input', recalc);
        price.addEventListener('input', recalc);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-xs btn-outline-danger';
        remove.textContent = '×';
        remove.addEventListener('click', () => removeRow(i));

        const cells = [document.createElement('td'), document.createElement('td'), document.createElement('td'), document.createElement('td'), document.createElement('td')];
        cells[0].append(catalogId, description, name);
        cells[1].append(qty);
        cells[2].append(price);
        cells[3].className = 'subtotal text-end';
        cells[3].textContent = 'Rp 0';
        cells[4].append(remove);

        const tr = document.createElement('tr');
        tr.id = 'row-' + i;
        tr.append(...cells);

        document.getElementById('itemRows').append(tr);
        recalc();
    }

    function removeRow(i) {
        document.getElementById('row-' + i)?.remove();
        recalc();
    }

    function addFromCatalog() {
        const picker = document.getElementById('catalogPicker');
        const opt = picker.selectedOptions[0];
        if (!opt || !opt.value) return;

        addRow({ service_catalog_id: opt.value, name: opt.dataset.name, qty: 1, unit_price: opt.dataset.price });
        picker.value = '';
    }

    function applyClient() {
        const opt = document.getElementById('clientPicker').selectedOptions[0];
        document.getElementById('serviceClientId').value = opt.value || '';
        if (!opt.value) return;

        const c = JSON.parse(opt.dataset.client);
        document.getElementById('clientName').value = c.name ?? '';
        document.getElementById('clientInstitution').value = c.institution ?? '';
        document.getElementById('clientEmail').value = c.email ?? '';
        document.getElementById('clientPhone').value = c.phone ?? '';
        document.getElementById('clientAddress').value = c.address ?? '';
    }

    function recalc() {
        let subtotal = 0;
        document.querySelectorAll('#itemRows tr').forEach(tr => {
            const qty   = parseFloat(tr.querySelector('.qty').value) || 0;
            const price = digits(tr.querySelector('.price').value);
            const line  = qty * price;
            subtotal += line;
            tr.querySelector('.subtotal').textContent = rupiah(line);
        });

        const discount = digits(document.getElementById('discount').value);
        document.getElementById('previewSubtotal').textContent = rupiah(subtotal);
        document.getElementById('previewTotal').textContent = rupiah(Math.max(subtotal - discount, 0));
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (existingItems.length) {
            existingItems.forEach(addRow);
        } else {
            addRow();
        }
    });
</script>
@endpush
