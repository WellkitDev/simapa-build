@extends('layouts.master')
@section('title', 'Edit Order Jurnal - SiMAPA')

@push('plugin-styles')
    <link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
    @php($d = $order->details)

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h5 class="mb-0">Edit Order Jurnal</h5>
        </div>
        <div>
            <a href="{{ route('order.book.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('order.journal.update', $order->code_order) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        @if ($errors->any())
            <div class="alert alert-danger mb-3">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Section 1: Informasi Dasar Order -->
        <div class="row">
            <div class="col-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">Informasi Dasar Order</h6>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Code Order</label>
                                <input type="text" class="form-control" value="{{ $order->code_order }}" disabled>
                                <small class="text-muted">Kode order tidak dapat diubah.</small>
                            </div>

                            <div class="col-6 mb-3">
                                <label class="form-label">Jenis Layanan <span class="text-danger">*</span></label>
                                <select name="type" class="form-select" required>
                                    <option value="">Pilih jenis</option>
                                    <option value="at_mandiri" {{ old('type', $d->type) === 'at_mandiri' ? 'selected' : '' }}>Mandiri</option>
                                    <option value="at_kolab" {{ old('type', $d->type) === 'at_kolab' ? 'selected' : '' }}>Kolaborasi</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            @include('orders.partials.title-select', [
                                'titles'   => $titles,
                                'selected' => old('title_id', $d->title_id ?: $d->title),
                            ])
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Scope</label>
                                <select name="scope_id" class="form-select select2" id="scope_id" data-tags="true">
                                    <option value="">Tidak ada / opsional</option>
                                    @foreach ($scopes as $scope)
                                        <option value="{{ $scope->id }}" {{ old('scope_id', $d->scopes->first()?->id) == $scope->id ? 'selected' : '' }}>{{ $scope->scope }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-6 mb-3">
                                <label class="form-label">Indeksasi <span class="text-danger">*</span></label>
                                <select name="indexation" class="form-select select2" required>
                                    <option value="">Pilih jenis</option>
                                    @foreach ([
                                        'sinta 1' => 'Sinta 1', 'sinta 2' => 'Sinta 2', 'sinta 3' => 'Sinta 3',
                                        'sinta 4' => 'Sinta 4', 'sinta 5' => 'Sinta 5', 'sinta 6' => 'Sinta 6',
                                        'scopus q1' => 'Scopus Q1', 'scopus q2' => 'Scopus Q2',
                                        'scopus q3' => 'Scopus Q3', 'scopus q4' => 'Scopus Q4',
                                        'scopus q5' => 'Scopus Q5', 'scopus q6' => 'Scopus Q6',
                                        'ebsco' => 'EBSCO', 'doaj' => 'DOAJ',
                                        'coppernicus' => 'Copernicus', 'google scholar' => 'Google Scholar',
                                    ] as $val => $label)
                                        <option value="{{ $val }}" {{ old('indexation', $d->indexation) === $val ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Jenis Naskah</label>
                                <select name="naskah_type" class="form-select" required>
                                    <option value="dibuatkan" {{ old('naskah_type', $d->naskah_type) === 'dibuatkan' ? 'selected' : '' }}>Dibuatkan oleh tim</option>
                                    <option value="mandiri" {{ old('naskah_type', $d->naskah_type) === 'mandiri' ? 'selected' : '' }}>Naskah mandiri (author kirim sendiri)</option>
                                </select>
                            </div>

                            <div class="col-6 mb-3">
                                <label class="form-label">Jenis Publikasi</label>
                                <select name="publication_type" class="form-select" required>
                                    <option value="regular" {{ old('publication_type', $d->publication_type) === 'regular' ? 'selected' : '' }}>Regular</option>
                                    <option value="fastrack" {{ old('publication_type', $d->publication_type) === 'fastrack' ? 'selected' : '' }}>Fastrack</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Penulis (Authors) -->
        <div class="row">
            <div class="col-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">Data Penulis (Author)</h6>

                        <div id="authors-container">
                            @php($authors = $d->authors->sortBy('pivot.position'))
                            @foreach ($authors as $i => $author)
                            <div class="author-row mb-4 p-4 border rounded bg-light">
                                <div class="row">
                                    <div class="col-12 col-md-5">
                                        <input type="text" name="authors[{{ $i }}][name]" class="form-control mb-2"
                                            placeholder="Nama lengkap + gelar" required value="{{ old("authors.{$i}.name", $author->name) }}">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <input type="text" name="authors[{{ $i }}][affiliation]" class="form-control mb-2"
                                            placeholder="Afiliasi / Kampus" value="{{ old("authors.{$i}.affiliation", $author->affiliation) }}">
                                    </div>
                                    <div class="col-12 col-md-1 mb-2 text-end">
                                        <button type="button" class="btn btn-danger btn-sm remove-author">×</button>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12 col-md-4">
                                        <input type="email" name="authors[{{ $i }}][email]" class="form-control mb-2"
                                            placeholder="Email" value="{{ old("authors.{$i}.email", $author->email) }}">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <input type="text" name="authors[{{ $i }}][phone]" class="form-control mb-2"
                                            placeholder="No. WA" value="{{ old("authors.{$i}.phone", $author->phone) }}">
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <input type="number" name="authors[{{ $i }}][position]" class="form-control mb-2"
                                            placeholder="Urutan (1,2,3...)" value="{{ old("authors.{$i}.position", $author->pivot->position) }}">
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>

                        <button type="button" id="add-author" class="btn btn-outline-primary btn-sm">+ Tambah Penulis</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 3: Informasi Tambahan -->
        <div class="row">
            <div class="col-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">Informasi Tambahan</h6>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Issue Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="issued_at" required
                                    value="{{ old('issued_at', optional($order->ordered_at)->format('Y-m-d')) }}">
                            </div>
                            <div class="col-md-6 sm-12 mb-3">
                                <label class="form-label">Total Biaya (Rp) <span class="text-danger">*</span></label>
                                <input type="number" name="cost_amount" class="form-control" required min="0"
                                    step="1000" value="{{ old('cost_amount', $d->cost_amount) }}">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 sm-12 mb-3">
                                <label class="form-label">Kontak Person <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="contact_phone"
                                    value="{{ old('contact_phone', $order->contact->cp_phone ?? '') }}" required>
                            </div>
                            <div class="col-md-6 sm-12 mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="contact_email"
                                    value="{{ old('contact_email', $order->contact->cp_email ?? '') }}" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Catatan Tambahan</label>
                            <textarea name="note" class="form-control" rows="3">{{ old('note', $order->note) }}</textarea>
                        </div>

                        <div class="text-start">
                            <button type="submit" class="btn btn-sm btn-primary">Simpan Perubahan</button>
                            <a href="{{ route('order.book.index') }}" class="btn btn-sm btn-outline-secondary ms-2">Kembali</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush

@push('custom-scripts')
    <script src="{{ asset('assets/js/select2.js') }}"></script>
    <script>
        let authorIndex = {{ $d->authors->count() }};

        document.getElementById('add-author').addEventListener('click', function() {
            const container = document.getElementById('authors-container');
            const newRow = document.createElement('div');
            newRow.className = 'author-row mb-4 p-4 border rounded bg-light';
            newRow.innerHTML = `
                <!-- isi sama seperti sebelumnya, cuma authorIndex baru -->
                <div class="row">
                    <div class="col-12 col-md-5">
                        <input type="text" name="authors[${authorIndex}][name]" class="form-control mb-2" placeholder="Nama lengkap + gelar" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <input type="text" name="authors[${authorIndex}][affiliation]" class="form-control mb-2" placeholder="Afiliasi / Kampus">
                    </div>
                    <div class="col-12 col-md-1 mb-2 text-end">
                        <button type="button" class="btn btn-danger btn-sm remove-author">×</button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12 col-md-4">
                        <input type="email" name="authors[${authorIndex}][email]" class="form-control mb-2" placeholder="Email">
                    </div>
                    <div class="col-12 col-md-4">
                        <input type="text" name="authors[${authorIndex}][phone]" class="form-control mb-2" placeholder="No. WA">
                    </div>
                    <div class="col-12 col-md-3">
                        <input type="number" name="authors[${authorIndex}][position]" class="form-control mb-2" placeholder="Urutan" value="${authorIndex + 1}">
                    </div>
                </div>
            `;
            container.appendChild(newRow);
            authorIndex++;
        });

        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-author')) {
                if (document.querySelectorAll('.author-row').length > 1) {
                    e.target.closest('.author-row').remove();
                }
            }
        });
    </script>
    <script>
    (function () {
        var el = document.getElementById('title_id');
        if (!el) return;
        function applyTitle() {
            var opt = el.options[el.selectedIndex];
            if (!opt || !opt.dataset || !opt.dataset.tipeNaskah) return;
            var typeSel = document.querySelector('[name="type"]');
            if (typeSel) typeSel.value = 'at_' + (opt.dataset.tipeNaskah === 'kolaborasi' ? 'kolab' : 'mandiri');
            if (opt.dataset.scopeId) {
                var sc = document.getElementById('scope_id');
                if (sc) { sc.value = opt.dataset.scopeId; if (window.jQuery) jQuery(sc).trigger('change'); }
            }
            var ix = (opt.dataset.indeksasi || '').toLowerCase();
            if (ix) {
                var idxSel = document.querySelector('[name="indexation"]');
                if (idxSel) {
                    for (var i = 0; i < idxSel.options.length; i++) {
                        if (idxSel.options[i].value.toLowerCase() === ix) { idxSel.selectedIndex = i; if (window.jQuery) jQuery(idxSel).trigger('change'); break; }
                    }
                }
            }
        }
        if (window.jQuery) { jQuery(el).on('change', applyTitle); } else { el.addEventListener('change', applyTitle); }
    })();
    </script>
@endpush
