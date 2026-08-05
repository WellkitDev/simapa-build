@extends('layouts.master')
@section('title', 'Edit Order ' . $order->code_order . ' - SiMAPA')

@push('plugin-styles')
    <link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h5 class="mb-0">Edit Order Buku</h5>
        </div>
        <div>
            <span class="badge bg-secondary me-2">Status: {{ strtoupper($order->status) }}</span>
            <a href="{{ route('order.book.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('order.book.update', $order->id) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

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
                            </div>

                            <div class="col-6 mb-3">
                                <label class="form-label">Jenis Layanan <span class="text-danger">*</span></label>

                                <select name="type" class="form-select" required>
                                    @if (str_starts_with($order->details->type, 'bk_'))
                                        <option value="bk_mandiri"
                                            {{ $order->details->type == 'bk_mandiri' ? 'selected' : '' }}>
                                            Buku Mandiri
                                        </option>
                                        <option value="bk_kolab" {{ $order->details->type == 'bk_kolab' ? 'selected' : '' }}>
                                            Buku
                                            Kolaborasi
                                        </option>
                                    @else
                                        <option value="at_mandiri"
                                            {{ $order->details->type == 'at_mandiri' ? 'selected' : '' }}>
                                            Artikel Mandiri
                                        </option>
                                        <option value="at_kolab" {{ $order->details->type == 'at_kolab' ? 'selected' : '' }}>
                                            Artikel
                                            Kolaborasi
                                        </option>
                                    @endif
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            @include('orders.partials.title-select', [
                                'titles'   => $titles,
                                'selected' => old('title_id', $order->details->title_id ?: $order->details->title),
                            ])
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Scope</label>
                                <select name="scope_id" class="form-select select2" id="scope_id">
                                    <option value="">Tidak ada / opsional</option>
                                    @foreach ($scopes as $scope)
                                        <option value="{{ $scope->id }}"
                                            {{ $order->details->scopes->contains($scope->id) == $scope->id ? 'selected' : '' }}>
                                            {{ $scope->scope }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            @if (str_starts_with($order->details->type, 'bk_'))
                                <div class="col-6 mb-3">
                                    <label class="form-label">Jumlah Bab</label>
                                    <input type="number" name="chapters" class="form-control" min="1"
                                        value="{{ $order->details->chapters }}">
                                </div>
                            @else
                                <div class="col-6 mb-3">
                                    <label class="form-label">Indeksasi <span class="text-danger">*</span></label>
                                    <select name="indexation" class="form-select select2">

                                        <option value="sinta 1"
                                            {{ $order->details->indexation == 'sinta 1' ? 'selected' : '' }}>Sinta 1</option>
                                        <option value="sinta 2"
                                            {{ $order->details->indexation == 'sinta 2' ? 'selected' : '' }}>Sinta 2</option>
                                        <option value="sinta 3"
                                            {{ $order->details->indexation == 'sinta 3' ? 'selected' : '' }}>Sinta 3</option>
                                        <option value="sinta 4"
                                            {{ $order->details->indexation == 'sinta 4' ? 'selected' : '' }}>Sinta 4</option>
                                        <option value="sinta 5"
                                            {{ $order->details->indexation == 'sinta 5' ? 'selected' : '' }}>Sinta 5</option>
                                        <option value="sinta 6"
                                            {{ $order->details->indexation == 'sinta 6' ? 'selected' : '' }}>Sinta 6</option>
                                        <option value="scopus q1"
                                            {{ $order->details->indexation == 'scopus q1' ? 'selected' : '' }}>Scopus Q1
                                        </option>
                                        <option value="scopus q2"
                                            {{ $order->details->indexation == 'scopus q2' ? 'selected' : '' }}>Scopus Q2
                                        </option>
                                        <option value="scopus q3"
                                            {{ $order->details->indexation == 'scopus q3' ? 'selected' : '' }}>Scopus Q3
                                        </option>
                                        <option value="scopus q4"
                                            {{ $order->details->indexation == 'scopus q4' ? 'selected' : '' }}>Scopus Q4
                                        </option>
                                        <option value="scopus q5"
                                            {{ $order->details->indexation == 'scopus q5' ? 'selected' : '' }}>Scopus Q5
                                        </option>
                                        <option value="scopus q6"
                                            {{ $order->details->indexation == 'scopus q6' ? 'selected' : '' }}>Scopus Q6
                                        </option>
                                        <option value="ebsco" {{ $order->details->indexation == 'ebsco' ? 'selected' : '' }}>
                                            EBSCO</option>
                                        <option value="doaj" {{ $order->details->indexation == 'doaj' ? 'selected' : '' }}>
                                            DOAJ</option>
                                        <option value="coppernicus"
                                            {{ $order->details->indexation == 'coppernicus' ? 'selected' : '' }}>Copernicus
                                        </option>
                                        <option value="google scholar"
                                            {{ $order->details->indexation == 'google scholar' ? 'selected' : '' }}>Google
                                            Scholar</option>
                                    </select>
                                </div>
                            @endif
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Jenis Naskah</label>
                                <select name="naskah_type" class="form-select" required>
                                    <option value="dibuatkan"
                                        {{ $order->details->naskah_type == 'dibuatkan' ? 'selected' : '' }}>Dibuatkan
                                        oleh tim</option>
                                    <option value="mandiri" {{ $order->details->naskah_type == 'mandiri' ? 'selected' : '' }}>
                                        Naskah
                                        mandiri</option>
                                </select>
                            </div>

                            <div class="col-6 mb-3">
                                <label class="form-label">Jenis Publikasi</label>
                                <select name="publication_type" class="form-select" required>
                                    <option value="regular"
                                        {{ $order->details->publication_type == 'regular' ? 'selected' : '' }}>Regular
                                    </option>
                                    <option value="fastrack"
                                        {{ $order->details->publication_type == 'fastrack' ? 'selected' : '' }}>
                                        Fastrack</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Data Penulis -->
        <div class="row">
            <div class="col-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">Data Penulis (Author)</h6>

                        <div id="authors-container">
                            @foreach ($order->details->authors as $index => $author)
                                <div class="author-row mb-4 p-4 border rounded bg-light">
                                    <input type="hidden" name="authors[{{ $index }}][id]" value="{{ $author->id }}">
                                    <div class="row">
                                        <div class="col-12 col-md-5">
                                            <input type="text" name="authors[{{ $index }}][name]"
                                                class="form-control mb-2" value="{{ $author->name }}" required>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <input type="text" name="authors[{{ $index }}][affiliation]"
                                                class="form-control mb-2" value="{{ $author->affiliation }}">
                                        </div>
                                        <div class="col-12 col-md-1 mb-2 text-end">
                                            <button type="button" class="btn btn-danger btn-sm remove-author">×</button>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-12 col-md-4">
                                            <input type="email" name="authors[{{ $index }}][email]"
                                                class="form-control mb-2" value="{{ $author->email }}">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <input type="text" name="authors[{{ $index }}][phone]"
                                                class="form-control mb-2" value="{{ $author->phone }}">
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <input type="number" name="authors[{{ $index }}][position]"
                                                class="form-control mb-2" value="{{ $author->pivot->position }}">
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <button type="button" id="add-author" class="btn btn-outline-primary btn-sm">+ Tambah
                            Penulis</button>
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
                                <label class="form-label">Issue Date</label>
                                <input type="datetime-local" class="form-control" name="issued_at"
                                    value="{{ $order->ordered_at ? \Carbon\Carbon::parse($order->ordered_at)->format('Y-m-d\TH:i') : '' }}"
                                    required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Total Biaya (Rp)</label>
                                <input type="number" name="cost_amount" class="form-control"
                                    value="{{ $order->details->cost_amount }}" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Kontak Person</label>
                                <input type="text" class="form-control" name="contact_phone"
                                    value="{{ $order->contact->cp_phone }}" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="contact_email"
                                    value="{{ $order->contact->cp_email }}" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Catatan Tambahan</label>
                            <textarea name="note" class="form-control" rows="3">{{ $order->note }}</textarea>
                        </div>

                        <div class="text-start">
                            <button type="submit" class="btn btn-sm btn-primary">Update Order</button>
                            <a href="{{ route('order.book.index') }}" class="btn btn-sm btn-outline-secondary ms-2">Batal</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('custom-scripts')
    <script>
        let authorIndex = {{ $order->details->first()->authors->count() }};
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
            if (!opt || !opt.dataset || !opt.dataset.tipeNaskah) return; // judul baru / kosong
            var typeSel = document.querySelector('[name="type"]');
            if (typeSel) typeSel.value = 'bk_' + (opt.dataset.tipeNaskah === 'kolaborasi' ? 'kolab' : 'mandiri');
            if (opt.dataset.scopeId) {
                var sc = document.getElementById('scope_id');
                if (sc) { sc.value = opt.dataset.scopeId; if (window.jQuery) jQuery(sc).trigger('change'); }
            }
        }
        if (window.jQuery) { jQuery(el).on('change', applyTitle); } else { el.addEventListener('change', applyTitle); }
    })();
    </script>
@endpush
