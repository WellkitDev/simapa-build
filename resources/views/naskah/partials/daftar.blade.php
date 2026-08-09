{{-- Tampilan Daftar: isi yang sama dengan papan, dibaca sebagai tabel. --}}
<div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-centered datatable dt-responsive nowrap w-100">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Kode Order</th>
                    <th>Judul</th>
                    <th>Tahap</th>
                    <th>PJ</th>
                    <th>Pelaksana</th>
                    <th>Lama</th>
                    <th>Target</th>
                    <th>Prioritas</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($kartu->flatten(1) as $i => $k)
                    @php $p = $k['progress']; $d = $p->orderDetail; @endphp
                    <tr class="{{ $p->isOverdue() ? 'table-danger' : '' }}">
                        <td>{{ $i + 1 }}</td>
                        <td>
                            <a href="{{ route('naskah.show', $p->order_detail_id) }}" class="fw-bold">
                                {{ $d?->order?->code_order ?? '—' }}
                            </a>
                            @if ($k['jumlah'] > 1)
                                <span class="badge bg-light text-dark border">{{ $k['jumlah'] }} order</span>
                            @endif
                        </td>
                        <td class="dt-judul">{{ $d?->title ?? '—' }}</td>
                        <td>{{ $p->stageLabelId() }}</td>
                        <td>{{ $p->pj?->name ?? '—' }}</td>
                        <td>{{ $p->pelaksana?->name ?? '—' }}</td>
                        <td>{{ $p->daysInStage() }} hari</td>
                        <td>{{ $p->target_date?->translatedFormat('j M Y') ?? '—' }}</td>
                        <td>{{ ucfirst($p->priority ?? 'normal') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div>
