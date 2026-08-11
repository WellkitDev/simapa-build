@extends('layouts.master')
@section('title', 'Klien Jasa - SiMAPA')

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
                    <h6 class="card-title mb-0">Klien Jasa</h6>
                    @can('service_client.manage')
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#clientModal"
                                onclick="resetClientForm()">+ Tambah Klien</button>
                    @endcan
                </div>

                {{-- WAJIB: layouts/master hanya merender session success/error/info, BUKAN $errors.
                     Tanpa blok ini, email yang salah format memantul kembali ke daftar tanpa satu
                     pun tanda dan operator mengira aplikasinya macet. --}}
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

                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap" style="width:100%;">
                        <thead>
                            <tr><th>Nama</th><th>Instansi</th><th>Email</th><th>Telepon</th><th>Invoice</th><th>Aksi</th></tr>
                        </thead>
                        <tbody>
                            @foreach($clients as $c)
                            <tr>
                                <td><a href="{{ route('service.client.show', $c->id) }}">{{ $c->name }}</a></td>
                                <td>{{ $c->institution ?? '-' }}</td>
                                <td>{{ $c->email ?? '-' }}</td>
                                <td>{{ $c->phone ?? '-' }}</td>
                                <td><span class="badge bg-info">{{ $c->invoices_count }}</span></td>
                                <td>
                                    @can('service_client.manage')
                                    {{-- Payload dirakit eksplisit, bukan $c->toJson(): hanya kolom
                                         yang memang dipakai form yang perlu sampai ke browser. --}}
                                    <button class="btn btn-xs btn-outline-secondary"
                                            data-bs-toggle="modal" data-bs-target="#clientModal"
                                            data-client="{{ json_encode([
                                                'id'          => $c->id,
                                                'name'        => $c->name,
                                                'institution' => $c->institution,
                                                'email'       => $c->email,
                                                'phone'       => $c->phone,
                                                'address'     => $c->address,
                                                'note'        => $c->note,
                                            ]) }}" onclick="fillClientForm(this)">Edit</button>
                                    {{-- data-confirm: listener SweetAlert terdelegasi di layouts/master,
                                         dipakai seluruh aksi destruktif lain di aplikasi ini. --}}
                                    <form action="{{ route('service.client.destroy', $c->id) }}" method="POST" class="d-inline"
                                          data-confirm="Hapus klien ini? Invoice lamanya tetap utuh.">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-xs btn-outline-danger">Hapus</button>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@can('service_client.manage')
<div class="modal fade" id="clientModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="clientForm" action="{{ route('service.client.store') }}">
            @csrf
            <input type="hidden" name="_method" id="clientMethod" value="POST">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="clientModalTitle">Tambah Klien</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">Nama</label>
                        <input type="text" name="name" id="clientName" class="form-control" required maxlength="190">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Instansi</label>
                        <input type="text" name="institution" id="clientInstitution" class="form-control" maxlength="190">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="clientEmail" class="form-control" maxlength="190">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Telepon</label>
                        <input type="text" name="phone" id="clientPhone" class="form-control" maxlength="40">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Alamat</label>
                        <textarea name="address" id="clientAddress" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Catatan</label>
                        <textarea name="note" id="clientNote" class="form-control" rows="2"></textarea>
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
    // Rute update dari template bernama, bukan URL yang diketik tangan: kalau
    // prefiks `layanan` kelak berpindah, tombol Edit ikut pindah sendiri.
    const CLIENT_UPDATE_URL = "{{ route('service.client.update', ['id' => '__ID__']) }}";

    $(function () {
        $(".datatable").DataTable({
            pageLength: 25, responsive: true,
            columnDefs: [{ orderable: false, targets: 5 }],
            language: { emptyTable: "Belum ada klien jasa." }
        });
    });

    function resetClientForm() {
        document.getElementById('clientModalTitle').textContent = 'Tambah Klien';
        document.getElementById('clientForm').action = "{{ route('service.client.store') }}";
        document.getElementById('clientMethod').value = 'POST';
        ['clientName','clientInstitution','clientEmail','clientPhone','clientAddress','clientNote']
            .forEach(id => document.getElementById(id).value = '');
    }

    function fillClientForm(button) {
        const c = JSON.parse(button.dataset.client);
        document.getElementById('clientModalTitle').textContent = 'Edit Klien';
        document.getElementById('clientForm').action = CLIENT_UPDATE_URL.replace('__ID__', c.id);
        document.getElementById('clientMethod').value = 'PUT';
        document.getElementById('clientName').value = c.name;
        document.getElementById('clientInstitution').value = c.institution ?? '';
        document.getElementById('clientEmail').value = c.email ?? '';
        document.getElementById('clientPhone').value = c.phone ?? '';
        document.getElementById('clientAddress').value = c.address ?? '';
        document.getElementById('clientNote').value = c.note ?? '';
    }
</script>
@endpush
