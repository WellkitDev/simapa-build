@extends('layouts.master')
@section('title', 'Detail Judul - SiMAPA')

@section('content')
<div class="row">
    <div class="col-md-12">

            {{-- Info Order --}}
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-baseline mb-3">
                        <h3 class="mb-0">{{ $detail->title }}</h3>
                        <div class="d-flex gap-2">
                            <span class="badge bg-info text-uppercase">{{ $detail->type }}</span>
                            @role('superadmin')
                                <a href="{{ route('order.book.edit', $detail->order->code_order) }}"
                                   class="btn btn-sm btn-outline-warning">Edit Order</a>
                            @endrole
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-4">
                            <p class="text-muted mb-1">Kode Order</p>
                            <h5>{{ $detail->order->code_order }}</h5>
                        </div>
                        <div class="col-sm-4">
                            <p class="text-muted mb-1">Marketing</p>
                            <h5>{{ $detail->order->user->name ?? '-' }}</h5>
                        </div>
                        {{-- Angka uang hanya untuk role yang berwenang atas order/pembayaran.
                             Halaman ini sengaja terbuka bagi production/admin (butuh konteks naskah),
                             tapi admin & production tidak boleh melihat nilai order. --}}
                        @hasanyrole('marketing|manager|superadmin')
                        <div class="col-sm-4">
                            <p class="text-muted mb-1">Total Biaya</p>
                            <h5 class="text-success">Rp {{ number_format($detail->cost_amount, 0, ',', '.') }}</h5>
                        </div>
                        @endhasanyrole
                    </div>
                </div>
            </div>

            {{-- Timeline Progres --}}
            @php
                $progress  = $detail->titleProgress;
                $stages    = $progress->getStages();
                $currentIdx = array_search($progress->status, $stages);
            @endphp
            <div class="card mb-4">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="mb-0">Progress Naskah</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        @foreach ($stages as $idx => $stage)
                            @php
                                if ($idx < $currentIdx)      $cls = 'bg-success text-white';
                                elseif ($idx === $currentIdx) $cls = 'bg-primary text-white';
                                else                         $cls = 'bg-light text-muted';
                            @endphp
                            <div class="text-center" style="min-width:90px;">
                                <div class="badge {{ $cls }} p-2 mb-1 w-100">
                                    @if($idx < $currentIdx) ✓ @elseif($idx === $currentIdx) ● @else ○ @endif
                                </div>
                                <small class="{{ $idx === $currentIdx ? 'fw-bold' : '' }}">
                                    {{ Str::title(str_replace('_',' ',$stage)) }}
                                </small>
                            </div>
                            @if(!$loop->last)
                                <div class="d-flex align-items-center"><small class="text-muted">→</small></div>
                            @endif
                        @endforeach
                    </div>

                    {{-- Halaman ini READ-ONLY. Seluruh aksi naskah ada di Detail Naskah. --}}
                    @if(! $progress->getNextStatus())
                        <p class="text-success mb-0"><strong>✓ Naskah sudah di tahap akhir.</strong></p>
                    @endif
                    @can('naskah.view')
                        <a href="{{ route('naskah.show', $detail->id) }}" class="btn btn-sm btn-outline-primary mt-2">
                            Buka Detail Naskah
                        </a>
                    @endcan

                    @if(session('success'))
                        <div class="alert alert-success mt-3 py-2">{{ session('success') }}</div>
                    @endif
                    @if(session('error'))
                        <div class="alert alert-danger mt-3 py-2">{{ session('error') }}</div>
                    @endif
                </div>
            </div>

            {{-- Daftar Penulis --}}
            <div class="card mb-4">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="mb-0">Daftar Penulis</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr><th>No</th><th>Nama</th><th>Email / WA</th><th>Posisi</th></tr>
                            </thead>
                            <tbody>
                                @forelse($detail->authors as $author)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td><strong>{{ $author->name }}</strong></td>
                                    <td>{{ $author->email }}<br><small class="text-muted">{{ $author->phone }}</small></td>
                                    <td><span class="badge bg-light text-primary">Ke-{{ $author->pivot->position }}</span></td>
                                </tr>
                                @empty
                                <tr><td colspan="4" class="text-center">Belum ada penulis.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Riwayat Aktivitas --}}
            @php
                $logs    = $progress->logs->sortByDesc('created_at');
                $hasNew  = $progress->last_log_at && $progress->last_log_at->gt(now()->subDays(2));
            @endphp
            <div class="card">
                <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        Riwayat Aktivitas
                        <span class="badge bg-secondary ms-1">{{ $logs->count() }}</span>
                        @if($hasNew)<span class="badge bg-success ms-1">● baru</span>@endif
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Aktivitas</th><th>Perubahan</th><th>Oleh</th>
                                    <th>Tanggal</th><th>Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($logs as $log)
                                <tr>
                                    <td>
                                        <span class="badge bg-light text-dark border">{{ $log->eventLabel() }}</span>
                                        @if($log->is_correction)<span class="badge bg-danger ms-1">Koreksi</span>@endif
                                    </td>
                                    <td>
                                        @if($log->from_value || $log->to_value)
                                            <small class="text-muted">{{ $log->from_value ?? '—' }}</small>
                                            <span class="mx-1">→</span>
                                            <strong>{{ $log->to_value ?? '—' }}</strong>
                                        @else
                                            <small class="text-muted">—</small>
                                        @endif
                                    </td>
                                    <td>{{ $log->changedBy->name ?? '-' }}</td>
                                    <td><small>{{ $log->created_at->format('d/m/Y H:i') }}</small></td>
                                    <td>{{ $log->note ?? '-' }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="text-center text-muted">Belum ada riwayat.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

    </div>
</div>
@endsection
