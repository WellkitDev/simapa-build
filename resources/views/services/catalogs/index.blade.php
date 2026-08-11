@extends('layouts.master')
@section('title', 'Katalog Layanan - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card overflow-hidden">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-3">
                    <h6 class="card-title mb-0">Katalog Layanan</h6>
                    @can('service_catalog.manage')
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#catalogModal"
                                onclick="resetCatalogForm()">+ Tambah Layanan</button>
                    @endcan
                </div>

                <p class="text-muted small">
                    Harga di sini hanya acuan awal — saat membuat invoice, nominalnya tetap bisa ditimpa
                    sesuai kompleksitas pekerjaan. Mengubah harga di katalog <strong>tidak</strong>
                    mengubah invoice yang sudah terbit.
                </p>

                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap" style="width:100%;">
                        <thead>
                            <tr>
                                <th>Kategori</th><th>Layanan</th><th>Harga</th>
                                <th>Satuan</th><th>Aktif</th><th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($catalogs as $category => $rows)
                                @foreach($rows as $c)
                                <tr>
                                    <td><span class="badge bg-secondary">{{ \App\Models\ServiceCatalog::categoryLabel($category) }}</span></td>
                                    <td>
                                        {{ $c->name }}
                                        @if($c->description)
                                            <br><small class="text-muted">{{ $c->description }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $c->priceLabel() }}</td>
                                    <td>{{ $units[$c->unit] ?? '-' }}</td>
                                    <td>
                                        <span class="badge bg-{{ $c->is_active ? 'success' : 'secondary' }}">
                                            {{ $c->is_active ? 'Aktif' : 'Nonaktif' }}
                                        </span>
                                    </td>
                                    <td>
                                        @can('service_catalog.manage')
                                        <button class="btn btn-xs btn-outline-secondary"
                                                data-bs-toggle="modal" data-bs-target="#catalogModal"
                                                data-catalog="{{ $c->toJson() }}"
                                                onclick="fillCatalogForm(this)">Edit</button>
                                        <form action="{{ route('service.catalog.destroy', $c->id) }}" method="POST" class="d-inline"
                                              onsubmit="return confirm('Hapus layanan ini dari katalog?')">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-xs btn-outline-danger">Hapus</button>
                                        </form>
                                        @endcan
                                    </td>
                                </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@can('service_catalog.manage')
<div class="modal fade" id="catalogModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="catalogForm" action="{{ route('service.catalog.store') }}">
            @csrf
            <input type="hidden" name="_method" id="catalogMethod" value="POST">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="catalogModalTitle">Tambah Layanan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">Kategori</label>
                        <select name="category" id="catalogCategory" class="form-select" required>
                            @foreach($categories as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Nama Layanan</label>
                        <input type="text" name="name" id="catalogName" class="form-control" required maxlength="190">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-2">
                            <label class="form-label">Harga</label>
                            <input type="text" name="price" id="catalogPrice" class="form-control" required>
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label">Harga Maks <small class="text-muted">(bila berkisar)</small></label>
                            <input type="text" name="price_max" id="catalogPriceMax" class="form-control">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Satuan</label>
                        <select name="unit" id="catalogUnit" class="form-select">
                            <option value="">—</option>
                            @foreach($units as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Keterangan</label>
                        <textarea name="description" id="catalogDescription" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="catalogActive" class="form-check-input" value="1" checked>
                        <label class="form-check-label" for="catalogActive">Aktif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>
    $(function () {
        $(".datatable").DataTable({
            pageLength: 50,
            responsive: true,
            columnDefs: [{ orderable: false, targets: 5 }],
            language: { emptyTable: "Katalog masih kosong." }
        });
    });

    function resetCatalogForm() {
        document.getElementById('catalogModalTitle').textContent = 'Tambah Layanan';
        document.getElementById('catalogForm').action = "{{ route('service.catalog.store') }}";
        document.getElementById('catalogMethod').value = 'POST';
        document.getElementById('catalogName').value = '';
        document.getElementById('catalogPrice').value = '';
        document.getElementById('catalogPriceMax').value = '';
        document.getElementById('catalogDescription').value = '';
        document.getElementById('catalogActive').checked = true;
    }

    function fillCatalogForm(button) {
        const c = JSON.parse(button.dataset.catalog);
        document.getElementById('catalogModalTitle').textContent = 'Edit Layanan';
        document.getElementById('catalogForm').action = "{{ url('layanan/katalog') }}/" + c.id;
        document.getElementById('catalogMethod').value = 'PUT';
        document.getElementById('catalogCategory').value = c.category;
        document.getElementById('catalogName').value = c.name;
        document.getElementById('catalogPrice').value = c.price;
        document.getElementById('catalogPriceMax').value = c.price_max ?? '';
        document.getElementById('catalogUnit').value = c.unit ?? '';
        document.getElementById('catalogDescription').value = c.description ?? '';
        document.getElementById('catalogActive').checked = !!c.is_active;
    }
</script>
@endpush
