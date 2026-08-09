{{-- Tampilan Riwayat: audit total — setiap aksi tercatat, tak ada yang boleh menghapus. --}}
<div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-centered datatable dt-responsive nowrap w-100">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Waktu</th>
                    <th>Kode Order</th>
                    <th>Aksi</th>
                    <th>Dari</th>
                    <th>Ke</th>
                    <th>Oleh</th>
                    <th>Catatan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($riwayat as $i => $log)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $log->created_at?->translatedFormat('j M Y H:i') ?? '—' }}</td>
                        <td>{{ $log->titleProgress?->orderDetail?->order?->code_order ?? '—' }}</td>
                        <td>
                            {{ $log->eventLabel() }}
                            @if ($log->is_correction)
                                <span class="badge bg-warning text-dark">koreksi</span>
                            @endif
                        </td>
                        <td>{{ $log->from_value ?? '—' }}</td>
                        <td>{{ $log->to_value ?? '—' }}</td>
                        <td>{{ $log->changedBy?->name ?? 'Sistem' }}</td>
                        <td class="dt-judul">{{ $log->note ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div>
