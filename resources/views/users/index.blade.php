@extends('layouts.master')
<!-- Title pages active -->
@section('title', 'Manajemen User - SiMAPA')


@push('plugin-styles')
    <!-- Plugin css import here -->
    <!-- Plugin css import here -->
    <link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}"
        rel="stylesheet" />
@endpush

@section('content')
    <!-- Page content here -->
    <!-- row -->
    <div class="row">
        <div class="col-12 col-xl-12 grid-margin stretch-card">
            <div class="card overflow-hidden">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-books-baseline  mb-md-4">
                        <h6 class="card-title mb-0">Pengguna & Peran</h6>
                        <div class="btn-group" role="group" aria-label="Basic example">
                            @can('user.create')
                            <a href="#" type="button" class="btn btn-primary " data-bs-toggle="modal"
                                data-bs-target="#addUserModal"></i>Tambah</a>
                            @endcan
                        </div>
                    </div>
                    <div class="row mt-4">
                        <div class="table-responsive">
                            <table class="table table-centered datatable dt-responsive nowrap">
                                <thead class="table">
                                    <tr>
                                        <th class="pt-0">Nama</th>
                                        <th class="pt-0">Username</th>
                                        <th class="pt-0">Peran</th>
                                        <th class="pt-0">Status</th>
                                        <th class="pt-0">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($users as $item)
                                        <tr>
                                            <td>{{ $item->name }}</td>
                                            <td>{{ $item->username }}</td>
                                            <td>{{ ucfirst($item->getRoleNames()->implode(', ')) }}</td>
                                            <td>
                                                @if ($item->trashed())
                                                    <span class="badge bg-danger">Dihapus</span>
                                                @else
                                                    <span class="badge bg-{{ $item->is_active ? 'success' : 'secondary' }}">
                                                        {{ $item->is_active ? 'Aktif' : 'Nonaktif' }}
                                                    </span>
                                                @endif
                                            </td>
                                            <td>
                                                @can('user.edit')
                                                <a href="#" type="button" class="btn btn-sm btn-primary "
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editUserModal{{ $item->id }}"><i
                                                        data-feather="edit" class="icon-md"></i></a>
                                                @endcan
                                                {{-- <a href="{{ route('user.management.edit', $item) }}"
                                                    class="btn btn-sm btn-primary">
                                                    <i data-feather="edit" class="icon-md"></i>
                                                </a> --}}
                                                @can('user.delete')
                                                @if (!$item->trashed())
                                                    <form action="{{ route('user.management.destroy', $item->id) }}"
                                                        method="POST" style="display:inline">
                                                        @csrf @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-danger"
                                                            onsubmit="return confirm('Yakin ingin menghapus user: {{ $item->name }}?')">
                                                            <i data-feather="trash-2" class="icon-md"></i>
                                                        </button>
                                                    </form>
                                                @endif

                                                @if ($item->trashed())
                                                    <form action="{{ route('user.management.forceDelete', $item->id) }}"
                                                        method="POST" style="display:inline">
                                                        @csrf @method('POST')
                                                        <button type="submit" class="btn btn-sm btn-danger"
                                                            onclick="return confirm('Hapus PERMANEN?')"><i
                                                                data-feather="user-x" class="icon-md"></i></button>
                                                    </form>
                                                @endif
                                                @endcan

                                                @can('user.restore')
                                                @if ($item->trashed())
                                                    <form action="{{ route('user.management.restore', $item->id) }}"
                                                        method="POST" style="display:inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-warning"><i
                                                                data-feather="refresh-ccw" class="icon-md"></i></button>
                                                    </form>
                                                @endif
                                                @endcan
                                            </td>
                                        </tr>
                                        @can('user.edit')
                                        @include('users.edit', ['user' => $item])
                                        @endcan
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @include('users.create')
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- row -->
@endsection

@push('plugin-scripts')
    <!-- Plugin js import here -->
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush

@push('custom-scripts')
    <!-- Custom js here -->
    <script>
        $(function() {
            $(".datatable").DataTable({
                pageLength: 10,
                responsive: true,
                order: [
                    [1, "desc"]
                ],
                language: {
                    search: "Cari:",
                    lengthMenu: "Tampilkan _MENU_"
                }
            });
            $(".dataTables_length select, .dataTables_filter input").addClass("form-control form-control-sm");
        });
    </script>
    @if ($errors->any())
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var myModal = new bootstrap.Modal(document.getElementById('addUserModal'));
                myModal.show();
            });
        </script>
    @endif
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Fungsi generate password
            function generatePassword(length = 12) {
                const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
                let password = '';
                for (let i = 0; i < length; i++) {
                    password += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                return password;
            }

            // Init semua modal
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('shown.bs.modal', function() {
                    const modalId = this.id;
                    let prefix = '';

                    if (modalId === 'addUserModal') {
                        prefix = '';
                    } else if (modalId.startsWith('editUserModal')) {
                        prefix = modalId.replace('editUserModal', '');
                    } else {
                        return;
                    }

                    const genCheckbox = document.getElementById('generate_password' + prefix);
                    const passField = document.getElementById('password' + prefix);
                    const confirmField = document.getElementById('password_confirmation' + prefix);
                    const hiddenField = document.getElementById('generated_password' + prefix);
                    const showCheckbox = document.getElementById('show_password' + (prefix ? '_' +
                        prefix : '_create'));

                    if (!genCheckbox || !passField || !confirmField) return;

                    // === GENERATE PASSWORD ===
                    genCheckbox.addEventListener('change', function() {
                        if (this.checked) {
                            const newPass = generatePassword();
                            hiddenField.value = newPass;
                            passField.value = newPass;
                            confirmField.value = newPass;
                            passField.disabled = true;
                            confirmField.disabled = true;
                            passField.removeAttribute('required');
                            confirmField.removeAttribute('required');
                        } else {
                            hiddenField.value = '';
                            passField.value = '';
                            confirmField.value = '';
                            passField.disabled = false;
                            confirmField.disabled = false;
                            passField.setAttribute('required', 'required');
                            confirmField.setAttribute('required', 'required');
                        }
                    });

                    // === SHOW PASSWORD ===
                    if (showCheckbox) {
                        showCheckbox.addEventListener('change', function() {
                            const type = this.checked ? 'text' : 'password';
                            passField.type = type;
                            confirmField.type = type;
                        });
                    }

                    // Init state
                    if (genCheckbox.checked) {
                        genCheckbox.dispatchEvent(new Event('change'));
                    }

                    // Select2
                    $('#role_id' + prefix).select2({
                        dropdownParent: $('#' + modalId)
                    });
                });
            });
        });
    </script>


@endpush
