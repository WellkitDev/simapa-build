@extends('layouts.master')
<!-- Title pages active -->
@section('title', 'Detail Order Book - SiMAPA')

@push('plugin-styles')
    <!-- Plugin css import here -->
@endpush

@section('content')
    <div class="container py-5">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                Detail Order: <strong>ORD-XXXXXX</strong>
            </h1>
            <a href="#" class="btn btn-secondary">
                ← Kembali ke Daftar
            </a>
        </div>

        <!-- Informasi Order -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Informasi Order</h5>
            </div>

            <div class="card-body">
                <div class="row">

                    <!-- Kiri -->
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th width="30%">Kode Order</th>
                                <td>: ORD-XXXXXX</td>
                            </tr>
                            <tr>
                                <th>Jenis Layanan</th>
                                <td>: Buku Mandiri
                                    <span class="badge bg-info ms-2">Mandiri</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Judul</th>
                                <td>: Judul Naskah / Artikel</td>
                            </tr>
                            <tr>
                                <th>Jumlah Bab</th>
                                <td>: 10 Bab</td>
                            </tr>
                            <tr>
                                <th>Scope</th>
                                <td>: Pendidikan / Sosial</td>
                            </tr>
                            <tr>
                                <th>Target Indeksasi</th>
                                <td>: SINTA / Scopus</td>
                            </tr>
                            <tr>
                                <th>Jenis Publikasi</th>
                                <td>:
                                    <span class="badge bg-success">Reguler</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Status Order</th>
                                <td>:
                                    <span class="badge bg-warning">On Progress</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Marketing</th>
                                <td>: Nama Marketing</td>
                            </tr>
                        </table>
                    </div>

                    <!-- Kanan -->
                    <div class="col-md-6">
                        <h5 class="mb-3">Penulis</h5>

                        <ol class="list-group list-group-numbered">
                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                <div class="ms-2 me-auto">
                                    <div class="fw-bold">Nama Penulis Utama</div>
                                    <small class="text-muted">Universitas Contoh</small><br>
                                    <small class="text-muted">
                                        email@contoh.com | 08123456789
                                    </small>
                                </div>
                                <span class="badge bg-primary rounded-pill">Posisi 1</span>
                            </li>

                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                <div class="ms-2 me-auto">
                                    <div class="fw-bold">Nama Penulis Kedua</div>
                                    <small class="text-muted">Institusi Contoh</small>
                                </div>
                                <span class="badge bg-primary rounded-pill">Posisi 2</span>
                            </li>
                        </ol>
                    </div>

                </div>

                <!-- Catatan -->
                <div class="mt-4">
                    <h5>Catatan Tambahan</h5>
                    <div class="alert alert-info">
                        Catatan tambahan dari customer atau admin.
                    </div>
                </div>
            </div>
        </div>

        <!-- Rincian Keuangan -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Rincian Keuangan</h5>
            </div>

            <div class="card-body">
                <div class="row text-center mb-4">
                    <div class="col">
                        <h4>Rp 10.000.000</h4>
                        <small class="text-muted">Total Biaya</small>
                    </div>
                    <div class="col">
                        <h4>Rp 5.000.000</h4>
                        <small class="text-muted">Sudah Dibayar</small>
                    </div>
                    <div class="col">
                        <h4 class="text-danger">Rp 5.000.000</h4>
                        <small class="text-muted">Sisa Tagihan</small>
                    </div>
                </div>

                <h5>Riwayat Pembayaran</h5>

                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>INV</th>
                            <th>Tanggal</th>
                            <th>Jenis</th>
                            <th>Jumlah</th>
                            <th>Bukti</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>INVXXXX</td>
                            <td>01 Jan 2025 10:00</td>
                            <td>DP</td>
                            <td>Rp 5.000.000</td>
                            <td>
                                <a href="#" class="btn btn-sm btn-outline-primary">
                                    Lihat Bukti
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-success">Terbayar</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

            </div>
        </div>

        <!-- Invoice -->
        <div class="card shadow-sm">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Invoice</h5>
                <a href="#" class="btn btn-light btn-sm">
                    📄 Download PDF Invoice
                </a>
            </div>

            <div class="card-body text-center">
                <p><strong>No. Invoice:</strong> INV-XXXXXX</p>
                <p><strong>Tanggal Terbit:</strong> 01 Januari 2025</p>
                <p><strong>Jatuh Tempo:</strong> 15 Januari 2025</p>

                <h4 class="mt-4">
                    Status:
                    <span class="text-warning fw-bold">
                        MENUNGGU PELUNASAN
                    </span>
                </h4>
            </div>
        </div>

    </div>

@endsection

@push('plugin-scripts')
    <!-- Plugin js import here -->
@endpush

@push('custom-scripts')
    <!-- Custom js here -->
@endpush
