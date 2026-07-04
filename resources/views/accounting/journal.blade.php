@extends('layouts.master')
@section('title', 'Jurnal Kas - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $purposeBadge = ['pemasukan' => 'bg-success', 'operational' => 'bg-primary', 'harta' => 'bg-warning text-dark', 'umum' => 'bg-secondary'];
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Jurnal Kas</h5>
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:90px">
        <select name="month" class="form-select form-select-sm" style="width:130px">
            <option value="all" {{ $month === null ? 'selected' : '' }}>Semua bulan</option>
            @for($m = 1; $m <= 12; $m++)
                <option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>
            @endfor
        </select>
        <select name="account" class="form-select form-select-sm" style="width:160px">
            <option value="all" {{ $accountId === null ? 'selected' : '' }}>Semua akun</option>
            @foreach($accounts as $a)
                <option value="{{ $a->id }}" {{ $accountId === $a->id ? 'selected' : '' }}>{{ $a->name }}</option>
            @endforeach
        </select>
        <select name="jenis" class="form-select form-select-sm" style="width:130px">
            <option value="">Semua jenis</option>
            <option value="pemasukan" {{ $jenis === 'pemasukan' ? 'selected' : '' }}>Pemasukan</option>
            <option value="pengeluaran" {{ $jenis === 'pengeluaran' ? 'selected' : '' }}>Pengeluaran</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary">Filter</button>
    </form>
    <a href="{{ route('accounting.journal.export.csv', request()->query()) }}" class="btn btn-sm btn-outline-success">Export CSV</a>
    <a href="{{ route('accounting.journal.export.pdf', request()->query()) }}" class="btn btn-sm btn-outline-danger">Export PDF</a>
</div>

{{-- Kartu saldo per akun --}}
<div class="row">
    @foreach($balances['rows'] as $row)
        <div class="col-md-3 col-6 grid-margin stretch-card">
            <div class="card"><div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="text-muted small">{{ $row['account']->name }}</div>
                    @if($row['account']->purpose)
                        <span class="badge {{ $purposeBadge[$row['account']->purpose] ?? 'bg-secondary' }}">{{ \App\Models\CashAccount::PURPOSES[$row['account']->purpose] ?? $row['account']->purpose }}</span>
                    @endif
                </div>
                <div class="h5 mb-0">{{ $rp($row['saldo']) }}</div>
            </div></div>
        </div>
    @endforeach
    <div class="col-md-3 col-6 grid-margin stretch-card">
        <div class="card bg-dark text-white"><div class="card-body py-3">
            <div class="small text-white-50">Total Semua Akun</div>
            <div class="h5 mb-0">{{ $rp($balances['total']) }}</div>
        </div></div>
    </div>
</div>

{{-- Ringkasan periode (menghormati filter akun) --}}
<div class="row">
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Total Pemasukan{{ $accountId ? ' (akun ini)' : '' }}</div><div class="h5 mb-0 text-success">{{ $rp($totalIn) }}</div></div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Total Pengeluaran{{ $accountId ? ' (akun ini)' : '' }}</div><div class="h5 mb-0 text-danger">{{ $rp($totalOut) }}</div></div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Saldo Akhir Periode</div><div class="h5 mb-0">{{ $rp($saldoAkhir) }}</div></div></div></div>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <span class="text-muted small">Saldo awal periode: {{ $rp($opening) }} · Saldo awal {{ $accountId ? 'akun ini' : 'semua akun' }}: {{ $rp($saldoAwal) }}</span>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#transferForm">↔ Transfer Dana</button>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#accountForm">Kelola Akun</button>
            <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#entryForm">+ Tambah Transaksi</button>
        </div>
    </div>

    {{-- Transfer Dana --}}
    <div class="collapse mb-3" id="transferForm">
        <form method="POST" action="{{ route('accounting.transfer.store') }}" class="border rounded p-3">
            @csrf
            <div class="alert alert-info py-2 mb-3 small">
                <strong>Transfer = pemindahan dana antar akun sendiri</strong> (mis. dari <em>Kas Pemasukan</em> ke <em>Operational</em>/<em>Harta</em>).
                Ini <strong>BUKAN pemasukan/pengeluaran</strong> — tidak menambah atau mengurangi laba, hanya <strong>memindahkan saldo</strong> antar akun. Tercatat sebagai dua baris (keluar dari akun asal, masuk ke akun tujuan).
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-md-3"><label class="form-label small mb-1">Dari Akun</label>
                    <select name="from_account_id" class="form-select form-select-sm" required>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                </div>
                <div class="col-md-3"><label class="form-label small mb-1">Ke Akun</label>
                    <select name="to_account_id" class="form-select form-select-sm" required>@foreach($accounts as $a)<option value="{{ $a->id }}" {{ $loop->index === 1 ? 'selected' : '' }}>{{ $a->name }}</option>@endforeach</select>
                </div>
                <div class="col-md-2"><label class="form-label small mb-1">Nominal (Rp)</label><input type="text" name="amount" class="form-control form-control-sm money-mask" inputmode="numeric" min="1" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">Tanggal</label><input type="date" name="tanggal" value="{{ now()->format('Y-m-d') }}" class="form-control form-control-sm" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">Catatan</label><input name="catatan" class="form-control form-control-sm"></div>
            </div>
            <button class="btn btn-sm btn-info mt-2">Simpan Transfer</button>
        </form>
    </div>

    {{-- Tambah Transaksi --}}
    <div class="collapse mb-3" id="entryForm">
        <form method="POST" action="{{ route('accounting.entry.store') }}" class="border rounded p-3">
            @csrf
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small mb-1">Tanggal</label><input type="date" name="tanggal" value="{{ now()->format('Y-m-d') }}" class="form-control form-control-sm" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">Jenis</label><select name="jenis" class="form-select form-select-sm"><option value="pemasukan">Pemasukan</option><option value="pengeluaran">Pengeluaran</option></select></div>
                <div class="col-md-2"><label class="form-label small mb-1">Akun</label>
                    <select name="account_id" class="form-select form-select-sm">
                        @foreach($accounts as $a)<option value="{{ $a->id }}" {{ $a->is_income_default ? 'selected' : '' }}>{{ $a->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label small mb-1">Kategori</label>
                    <select name="cash_category_id" class="form-select form-select-sm">
                        <option value="">—</option>
                        @foreach($categories as $c)<option value="{{ $c->id }}" data-jenis="{{ $c->jenis }}">{{ $c->name }} ({{ \App\Models\CashCategory::JENIS[$c->jenis] }})</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small mb-1">Nominal</label><input type="text" name="amount" class="form-control form-control-sm money-mask" inputmode="numeric" min="0" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">Produk</label><select name="produk" class="form-select form-select-sm"><option value="">—</option>@foreach(\App\Models\CashEntry::PRODUK as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label small mb-1">Keterangan</label><input name="keterangan" class="form-control form-control-sm" required></div>
                <div class="col-md-3"><label class="form-label small mb-1">Ref (INV/Order)</label><input name="ref" class="form-control form-control-sm"></div>
                <div class="col-md-5"><label class="form-label small mb-1">Catatan</label><input name="catatan" class="form-control form-control-sm"></div>
            </div>
            <button class="btn btn-sm btn-primary mt-2">Simpan</button>
        </form>
    </div>

    {{-- Kelola Akun --}}
    <div class="collapse mb-3" id="accountForm">
        <div class="border rounded p-3">
            <div class="text-muted small fw-semibold mb-2">Akun / Bank — saldo awal & peran per akun</div>
            @foreach($allAccounts as $a)
                {{-- dua form bersaudara di dalam pembungkus flex — TANPA div yang menyeberang batas <form> --}}
                <div class="d-flex gap-2 align-items-end mb-2 flex-wrap">
                    <form method="POST" action="{{ route('accounting.account.update', $a->id) }}" class="d-flex gap-1 align-items-end flex-wrap flex-grow-1 m-0">
                        @csrf @method('PUT')
                        <div><label class="form-label small mb-0">Nama</label><input name="name" value="{{ $a->name }}" class="form-control form-control-sm" style="max-width:200px" required></div>
                        <div><label class="form-label small mb-0">Peran</label><select name="purpose" class="form-select form-select-sm" style="max-width:140px"><option value="">—</option>@foreach(\App\Models\CashAccount::PURPOSES as $pk => $pv)<option value="{{ $pk }}" {{ $a->purpose === $pk ? 'selected' : '' }}>{{ $pv }}</option>@endforeach</select></div>
                        <div><label class="form-label small mb-0">Saldo Awal</label><input type="text" name="opening_balance" value="{{ (int) $a->opening_balance }}" class="form-control form-control-sm money-mask" inputmode="numeric" style="max-width:130px" min="0"></div>
                        <label class="small mb-0"><input type="checkbox" name="is_income_default" value="1" {{ $a->is_income_default ? 'checked' : '' }}> akun pemasukan</label>
                        <label class="small mb-0"><input type="checkbox" name="active" value="1" {{ $a->active ? 'checked' : '' }}> aktif</label>
                        <button class="btn btn-xs btn-outline-primary">Simpan</button>
                    </form>
                    <form method="POST" action="{{ route('accounting.account.destroy', $a->id) }}" data-confirm="Hapus akun ini? (hanya bila tanpa transaksi & bukan akun pemasukan default)" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                </div>
            @endforeach
            <form method="POST" action="{{ route('accounting.account.store') }}" class="row g-1 mt-2 align-items-end">
                @csrf
                <div class="col-md-3"><input name="name" placeholder="Nama akun/bank baru…" class="form-control form-control-sm" required></div>
                <div class="col-md-2"><select name="purpose" class="form-select form-select-sm"><option value="">Peran…</option>@foreach(\App\Models\CashAccount::PURPOSES as $pk => $pv)<option value="{{ $pk }}">{{ $pv }}</option>@endforeach</select></div>
                <div class="col-md-2"><input type="text" name="opening_balance" value="0" class="form-control form-control-sm money-mask" inputmode="numeric" min="0" title="Saldo awal"></div>
                <div class="col-md-2"><label class="small mb-0"><input type="checkbox" name="is_income_default" value="1"> akun pemasukan</label></div>
                <div class="col-md-3"><button class="btn btn-xs btn-outline-success">+ Tambah Akun</button></div>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-sm datatable" style="width:100%">
            <thead><tr><th>Tgl</th><th>Kode</th><th>Keterangan</th><th>Akun</th><th>Kategori</th><th>Produk</th><th class="text-end">Pemasukan</th><th class="text-end">Pengeluaran</th><th class="text-end">Saldo</th><th>Ref</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($entries as $e)
                    <tr>
                        <td>{{ optional($e->tanggal)->format('d/m/y') }}</td>
                        <td>{{ $e->kode }}</td>
                        <td>
                            {{ $e->keterangan }}
                            @if($e->isTransfer())<br><span class="badge bg-info text-dark" title="Pemindahan dana antar akun sendiri — bukan pemasukan/pengeluaran">🔁 Transfer (internal)</span>@endif
                        </td>
                        <td>{{ $e->account?->name ?? '—' }}</td>
                        <td>{{ $e->category?->name ?? '—' }}</td>
                        <td>{{ \App\Models\CashEntry::PRODUK[$e->produk] ?? '—' }}</td>
                        <td class="text-end">{{ $e->isPemasukan() ? $rp($e->amount) : '' }}</td>
                        <td class="text-end">{{ ! $e->isPemasukan() ? $rp($e->amount) : '' }}</td>
                        <td class="text-end">{{ $rp($e->saldo ?? 0) }}</td>
                        <td>{{ $e->ref ?? '—' }}</td>
                        <td>
                            @if($e->source === 'payment')
                                <span class="badge bg-light text-muted border" title="Otomatis dari pembayaran">⚙ auto</span>
                            @elseif($e->isTransfer())
                                <form method="POST" action="{{ route('accounting.entry.destroy', $e->id) }}" data-confirm="Hapus transfer ini? Kedua sisi (keluar & masuk) akan dihapus." class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                            @else
                                <form method="POST" action="{{ route('accounting.entry.destroy', $e->id) }}" data-confirm="Hapus transaksi ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#catForm">Kelola Kategori</button>
        <div class="collapse mt-2" id="catForm">
            @foreach(\App\Models\CashCategory::JENIS as $jk => $jl)
                <div class="text-muted small fw-semibold mt-2">{{ $jl }}</div>
                @foreach($allCategories->where('jenis', $jk) as $c)
                    <form method="POST" action="{{ route('accounting.category.update', $c->id) }}" class="d-flex gap-1 mb-1 align-items-center">
                        @csrf @method('PUT')
                        <input type="hidden" name="jenis" value="{{ $c->jenis }}">
                        <input name="name" value="{{ $c->name }}" class="form-control form-control-sm" style="max-width:280px">
                        <label class="small mb-0"><input type="checkbox" name="active" value="1" {{ $c->active ? 'checked' : '' }}> aktif</label>
                        <button class="btn btn-xs btn-outline-primary">Simpan</button>
                    </form>
                @endforeach
                <form method="POST" action="{{ route('accounting.category.store') }}" class="d-flex gap-1 mt-1">
                    @csrf
                    <input type="hidden" name="jenis" value="{{ $jk }}">
                    <input name="name" placeholder="Kategori {{ $jl }} baru…" class="form-control form-control-sm" style="max-width:280px">
                    <button class="btn btn-xs btn-outline-success">+ Tambah</button>
                </form>
            @endforeach
        </div>
    </div>
</div></div></div>
@include('accounting.partials.money-mask')
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 25, order: [], language: { emptyTable: 'Belum ada transaksi.' } }); });</script>
@endpush
