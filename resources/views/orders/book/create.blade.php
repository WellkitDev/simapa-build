@extends('layouts.master')
@section('title', 'Create Order Book - SiMAPA')

@push('plugin-styles')
    <link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
    <div class="container py-5">
        <h1 class="mb-4">Tambah Order Buku Baru</h1>

        <form method="POST" action="{{ route('order.book.store') }}" enctype="multipart/form-data">
            @csrf

            <!-- Section 1: Informasi Dasar Order -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Informasi Dasar Order</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Code Order</label>
                            <input type="text" class="form-control" value="Auto generate" disabled>
                            <small class="text-muted">Kode akan dibuat otomatis (ORD-202512-0001 dst.)</small>
                        </div>

                        <div class="col-6 mb-3">
                            <label class="form-label">Jenis Layanan <span class="text-danger">*</span></label>
                            <select name="type" class="form-select" required>
                                <option value="">Pilih jenis</option>
                                <option value="bk_mandiri">Buku Mandiri</option>
                                <option value="bk_kolab">Buku Kolaborasi</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Judul <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required>
                    </div>

                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Scope</label>
                            <select name="scope_id" class="form-select select2" id="scope_id" data-tags="true">
                                <option value="">Tidak ada / opsional</option>
                                @foreach ($scopes as $scope)
                                    <option value="{{ $scope->id }}">{{ $scope->scope }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-6 mb-3">
                            <label class="form-label">Jumlah Bab (khusus buku)</label>
                            <input type="number" name="chapters" class="form-control" min="1">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Jenis Naskah</label>
                            <select name="naskah_type" class="form-select" required>
                                <option value="dibuatkan">Dibuatkan oleh tim</option>
                                <option value="mandiri">Naskah mandiri (author kirim sendiri)</option>
                            </select>
                        </div>

                        <div class="col-6 mb-3">
                            <label class="form-label">Jenis Publikasi</label>
                            <select name="publication_type" class="form-select" required>
                                <option value="regular">Regular</option>
                                <option value="fastrack">Fastrack</option>
                            </select>
                        </div>
                    </div>


                </div>
            </div>

            <!-- Section 2: Penulis (Authors) -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Data Penulis (Author)</h5>
                </div>
                <div class="card-body">

                    <div id="authors-container">
                        <div class="author-row mb-4 p-4 border rounded bg-light">
                            <div class="row">
                                <div class="col-12 col-md-5">
                                    <input type="text" name="authors[0][name]" class="form-control mb-2"
                                        placeholder="Nama lengkap + gelar" required>
                                </div>
                                <div class="col-12 col-md-6">
                                    <input type="text" name="authors[0][affiliation]" class="form-control mb-2"
                                        placeholder="Afiliasi / Kampus">
                                </div>
                                <div class="col-12 col-md-1 mb-2 text-end">
                                    <button type="button" class="btn btn-danger btn-sm remove-author">×</button>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12 col-md-4">
                                    <input type="email" name="authors[0][email]" class="form-control mb-2"
                                        placeholder="Email">
                                </div>
                                <div class="col-12 col-md-4">
                                    <input type="text" name="authors[0][phone]" class="form-control mb-2"
                                        placeholder="No. WA">
                                </div>
                                <div class="col-12 col-md-3">
                                    <input type="number" name="authors[0][position]" class="form-control mb-2"
                                        placeholder="Urutan (1,2,3...)" value="1">
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="button" id="add-author" class="btn btn-outline-primary btn-sm">+ Tambah
                        Penulis</button>

                </div>
            </div>

            <!-- Section 3: Opsi Tambahan -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Informasi Tambahan</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Issue Date <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="issued_at" required>
                        </div>
                        <div class="col-md-6 sm-12 mb-3">
                            <label class="form-label">Total Biaya (Rp) <span class="text-danger">*</span></label>
                            <input type="number" name="cost_amount" class="form-control" required min="0"
                                step="1000">
                        </div>
                        {{-- <div class="col-md-6 mb-3">
                            <label class="form-label">Due Date <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="dued_at" required>
                        </div> --}}
                    </div>
                    <div class="row">

                        <div class="col-md-6 sm-12 mb-3">
                            <label class="form-label">Kontak Person <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="contact_phone" value="" required>
                        </div>
                        <div class="col-md-6 sm-12 mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="contact_email" value="" required>
                        </div>
                        {{-- <div class="col-md-4 sm-12 mb-3">
                            <label class="form-label">Total Pembayaran (Rp) <span class="text-danger">*</span></label>
                            <input type="number" name="pay_amount" class="form-control" required min="0"
                                step="1000">
                        </div>
                        <div class="col-md-4 sm-12 mb-3">
                            <label class="form-label">Status Pembayaran</label>
                            <select name="status" class="form-select" required>
                                <option value="dp">Down Payment/DP</option>
                                <option value="lunas">Full Payment/Lunas</option>
                                <option value="pelunasan">Paydown/Pelunasan</option>
                            </select>
                        </div> --}}
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Catatan Tambahan</label>
                        <textarea name="note" class="form-control" rows="3"></textarea>
                    </div>
                    {{-- <div class="row">
                        <div class="col-md-4 sm-12 mb-3">
                            <label class="form-label">Struk Pembayaran</label>
                            <input type="file" class="form-control" name="struk_payment" accept="image/*,.pdf"
                                required>
                        </div>

                    </div> --}}
                    {{-- <div class="mb-3">
                        <label class="form-label">Catatan Tambahan</label>
                        <textarea name="note" class="form-control" rows="3"></textarea>
                    </div> --}}
                    {{-- <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="send_invoice_email" value="1"
                            id="sendInvoice">
                        <label class="form-check-label" for="sendInvoice">
                            Kirim invoice otomatis via email ke author utama setelah order disimpan
                        </label>
                    </div> --}}
                </div>
            </div>

            <!-- Tombol Aksi -->
            <div class="text-start">
                <button type="submit" class="btn btn-primary btn-lg">Simpan Order</button>
                <a href="" class="btn btn-secondary btn-lg ms-2">Batal</a>
            </div>
        </form>
    </div>
@endsection

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush

@push('custom-scripts')
    <script src="{{ asset('assets/js/select2.js') }}"></script>
    <script>
        let authorIndex = 1;

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
@endpush
