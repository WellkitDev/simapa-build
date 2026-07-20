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

                    {{-- Form Update Status (production, manager & superadmin) --}}
                    @hasanyrole('production|manager|superadmin')
                        @if($progress->getNextStatus())
                        <form method="POST" action="{{ route('title.progress.update', $progress->id) }}" class="row g-2 mt-2">
                            @csrf
                            <div class="col-md-4">
                                <label class="form-label form-label-sm">Ubah Status</label>
                                <select name="status" class="form-select form-select-sm" required>
                                    @role('superadmin')
                                        @foreach($stages as $stage)
                                            <option value="{{ $stage }}" {{ $stage === $progress->status ? 'selected' : '' }}>
                                                {{ Str::title(str_replace('_',' ',$stage)) }}
                                            </option>
                                        @endforeach
                                    @else
                                        <option value="{{ $progress->getNextStatus() }}">
                                            {{ Str::title(str_replace('_',' ',$progress->getNextStatus())) }}
                                        </option>
                                    @endrole
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label form-label-sm">Catatan</label>
                                <input type="text" name="note" class="form-control form-control-sm"
                                    placeholder="Catatan (wajib jika koreksi)"
                                    value="{{ old('note') }}">
                                @error('note') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Update Status</button>
                            </div>
                        </form>
                        @else
                            <p class="text-success mb-0"><strong>✓ Naskah sudah di tahap akhir.</strong></p>
                        @endif
                    @endhasanyrole

                    @if(session('success'))
                        <div class="alert alert-success mt-3 py-2">{{ session('success') }}</div>
                    @endif
                    @if(session('error'))
                        <div class="alert alert-danger mt-3 py-2">{{ session('error') }}</div>
                    @endif
                </div>
            </div>

            {{-- Kontrol Naskah --}}
            @hasanyrole('marketing|production|manager|superadmin')
            <div class="card mb-4">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="mb-0">Kontrol Naskah</h5>
                </div>
                <div class="card-body">
                    @php $tw = in_array($detail->type, ['bk_mandiri','bk_kolab'], true) ? 'terbit' : 'publish'; @endphp
                    <div class="row g-3">
                        @hasanyrole('production|manager|superadmin')
                        <div class="col-md-4">
                            <form method="POST" action="{{ route('manuscript.assign', $progress->id) }}">
                                @csrf
                                <label class="form-label form-label-sm">Editor / Penanggung Jawab</label>
                                <div class="d-flex gap-2">
                                    <select name="assigned_user_id" class="form-select form-select-sm">
                                        <option value="">— Belum ditugaskan —</option>
                                        @foreach(\App\Models\User::whereHas('roles', fn($q) => $q->whereIn('name', ['production','manager']))->orderBy('name')->get() as $ed)
                                            <option value="{{ $ed->id }}" {{ $progress->assigned_user_id == $ed->id ? 'selected' : '' }}>{{ $ed->name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                </div>
                                @error('assigned_user_id') <small class="text-danger">{{ $message }}</small> @enderror
                            </form>
                        </div>
                        <div class="col-md-4">
                            <form method="POST" action="{{ route('manuscript.priority', $progress->id) }}">
                                @csrf
                                <label class="form-label form-label-sm">Prioritas</label>
                                <div class="d-flex gap-2">
                                    <select name="priority" class="form-select form-select-sm">
                                        @foreach(['low','normal','high'] as $pr)
                                            <option value="{{ $pr }}" {{ $progress->priority === $pr ? 'selected' : '' }}>{{ ucfirst($pr) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                </div>
                            </form>
                        </div>
                        @endhasanyrole
                        <div class="col-md-4">
                            <form method="POST" action="{{ route('manuscript.target', $progress->id) }}">
                                @csrf
                                <label class="form-label form-label-sm">Target {{ $tw }}</label>
                                <div class="d-flex gap-2">
                                    <input type="date" name="target_date" class="form-control form-control-sm"
                                           value="{{ optional($progress->target_date)->format('Y-m-d') }}">
                                    <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                                </div>
                                @error('target_date') <small class="text-danger">{{ $message }}</small> @enderror
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            @endhasanyrole

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
                    @role('superadmin')
                        @if($logs->isNotEmpty())
                        <form method="POST" action="{{ route('manuscript.clearLog', $progress->id) }}"
                              onsubmit="return confirm('Bersihkan seluruh riwayat aktivitas judul ini? Tindakan ini tidak dapat dibatalkan.')">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger">Bersihkan Riwayat</button>
                        </form>
                        @endif
                    @endrole
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
