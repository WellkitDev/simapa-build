@extends('layouts.master')
@section('title', 'Invoice ' . $invoice->invoice_no . ' - SiMAPA')

@section('content')
    <div class="mb-3">@include('partials.tombol-kembali', ['ke' => route('service.invoice.index')])</div>
@php
    $workColors = ['belum' => 'secondary', 'proses' => 'warning', 'selesai' => 'success', 'batal' => 'danger'];
    $payColors  = ['belum' => 'secondary', 'dp' => 'info', 'lunas' => 'success'];
@endphp

{{-- WAJIB: layouts/master hanya merender session success/error/info, BUKAN $errors.
     Tanpa blok ini, kegagalan validasi pada form status/pembatalan di bawah memantul
     ke halaman ini tanpa satu pun tanda terlihat. --}}
@if ($errors->any())
    <div class="alert alert-danger">
        <strong>Gagal.</strong>
        <ul class="mb-0 mt-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row">
    {{-- TANPA `stretch-card`. Kelas itu memasang `display:flex` (arah baris) pada kolom
         dan memaksa tiap `> .card` jadi `min-width:100%`, jadi ia hanya benar untuk kolom
         berisi SATU kartu. Kolom ini berisi dua — invoice dan Riwayat Pembayaran — dan
         keduanya lalu berdiri bersebelahan selebar penuh, meluber menabrak kolom Status
         Pengerjaan di sebelahnya. --}}
    <div class="col-md-8 grid-margin">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h5 class="mb-1">{{ $invoice->invoice_no }}</h5>
                        <span class="badge bg-{{ $workColors[$invoice->work_status] ?? 'secondary' }}">{{ $invoice->workStatusLabel() }}</span>
                        <span class="badge bg-{{ $payColors[$invoice->payment_status] ?? 'secondary' }}">{{ $invoice->paymentStatusLabel() }}</span>
                        @if($invoice->sent_at)
                            <div><small class="text-muted">
                                Terkirim {{ $invoice->sent_at->format('d/m/Y H:i') }} ({{ $invoice->sent_count }}×)
                            </small></div>
                        @endif
                    </div>
                    <div class="d-flex gap-1">
                        {{-- Syarat kedua bukan keamanan (controller sudah menjaganya), tapi
                             afordans: tanpa itu manager melihat tombol Edit pada invoice
                             terkunci, mengkliknya, dan selalu dipantulkan balik. superadmin
                             tetap melihatnya karena memang boleh mengoreksi dengan alasan. --}}
                        @can('service_invoice.send')
                            <form action="{{ route('service.invoice.send', $invoice->id) }}" method="POST">
                                @csrf
                                {{-- @disabled + title berkutip. Versi sebelumnya merangkai
                                     'disabled title=Klien belum punya email' sebagai satu string
                                     tanpa kutip, jadi HTML memotong tooltip-nya di spasi pertama
                                     ("Klien") dan menyisakan tiga atribut sampah. --}}
                                <button class="btn btn-sm btn-outline-success"
                                        @disabled(! $invoice->client_email)
                                        title="{{ $invoice->client_email
                                            ? 'Kirim invoice ke ' . $invoice->client_email
                                            : 'Klien belum punya alamat email' }}">
                                    Kirim Email
                                </button>
                            </form>
                        @endcan
                        @can('service_invoice.export')
                            <a href="{{ route('service.invoice.pdf', $invoice->id) }}" target="_blank"
                               class="btn btn-sm btn-outline-primary">Unduh PDF</a>
                        @endcan
                        @can('service_invoice.edit')
                            @if ($invoice->isEditable() || auth()->user()->hasRole('superadmin'))
                                <a href="{{ route('service.invoice.edit', $invoice->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                            @endif
                        @endcan
                        @can('service_invoice.delete')
                            {{-- data-confirm: listener SweetAlert terdelegasi di layouts/master,
                                 dipakai seluruh aksi destruktif lain di aplikasi ini. --}}
                            <form action="{{ route('service.invoice.destroy', $invoice->id) }}" method="POST"
                                  data-confirm="Hapus invoice ini? Nomornya tidak akan dipakai ulang.">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">Hapus</button>
                            </form>
                        @endcan
                        <a href="{{ route('service.invoice.index') }}" class="btn btn-sm btn-outline-secondary">← Daftar</a>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <h6>Kepada</h6>
                        <p class="mb-0">
                            {{ $invoice->client_name }}<br>
                            {{ $invoice->client_institution ?? '-' }}<br>
                            {{ $invoice->client_email ?? '-' }}<br>
                            {{ $invoice->client_phone ?? '-' }}
                        </p>
                    </div>
                    <div class="col-6 text-end">
                        <h6>Tanggal</h6>
                        <p class="mb-0">
                            Terbit: {{ $invoice->issued_at?->format('d M Y') }}<br>
                            Jatuh tempo:
                            <span class="{{ $invoice->isOverdue() ? 'text-danger fw-bold' : '' }}">
                                {{ $invoice->due_at?->format('d M Y') ?? '-' }}
                            </span>
                        </p>
                    </div>
                </div>

                <h6>Rincian Layanan</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>#</th><th>Layanan</th><th class="text-end">Qty</th>
                                <th class="text-end">Harga</th><th class="text-end">Subtotal</th></tr>
                        </thead>
                        <tbody>
                            @foreach($invoice->items as $i => $item)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>
                                    {{ $item->name }}
                                    @if($item->description)<br><small class="text-muted">{{ $item->description }}</small>@endif
                                </td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($item->qty, 2, ',', '.'), '0'), ',') }}</td>
                                <td class="text-end">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr><td colspan="4" class="text-end">Subtotal</td>
                                <td class="text-end">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td></tr>
                            @if((float) $invoice->discount > 0)
                            <tr><td colspan="4" class="text-end">Diskon</td>
                                <td class="text-end">− Rp {{ number_format($invoice->discount, 0, ',', '.') }}</td></tr>
                            @endif
                            <tr class="fw-bold"><td colspan="4" class="text-end">Total</td>
                                <td class="text-end">Rp {{ number_format($invoice->total, 0, ',', '.') }}</td></tr>
                            <tr><td colspan="4" class="text-end">Terbayar</td>
                                <td class="text-end">Rp {{ number_format($invoice->paid_total, 0, ',', '.') }}</td></tr>
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end">{{ $invoice->isOverpaid() ? 'Lebih Bayar' : 'Sisa Tagihan' }}</td>
                                <td class="text-end {{ $invoice->isOverpaid() ? 'text-info' : ((float) $invoice->remaining > 0 ? 'text-danger' : 'text-success') }}">
                                    Rp {{ number_format($invoice->isOverpaid() ? $invoice->overpaidAmount() : $invoice->remaining, 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if($invoice->note)
                    <div class="alert alert-light border mt-2 mb-0"><strong>Catatan:</strong> {{ $invoice->note }}</div>
                @endif
                @if($invoice->internal_note)
                    <div class="alert alert-warning py-2 mt-2 mb-0">
                        <strong>Catatan internal</strong> (tidak tercetak): {{ $invoice->internal_note }}
                    </div>
                @endif
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-2">
                    <h6 class="card-title mb-0">Riwayat Pembayaran</h6>
                    @can('service_invoice.payment')
                        @unless($invoice->isCancelled())
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#paymentModal">
                                + Catat Pembayaran
                            </button>
                        @endunless
                    @endcan
                </div>

                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Tanggal</th><th>Jenis</th><th>Metode</th><th>Referensi</th>
                                <th class="text-end">Jumlah</th><th></th></tr>
                        </thead>
                        <tbody>
                            @forelse($invoice->payments as $p)
                            <tr>
                                <td>{{ $p->paid_at?->format('d M Y') }}</td>
                                <td>{{ $p->typeLabel() }}</td>
                                <td>{{ $p->methodLabel() }}</td>
                                <td><small>{{ $p->reference ?? '-' }}</small></td>
                                <td class="text-end">Rp {{ number_format($p->amount, 0, ',', '.') }}</td>
                                <td>
                                    @can('service_invoice.payment')
                                    <form action="{{ route('service.invoice.payment.destroy', [$invoice->id, $p->id]) }}"
                                          method="POST" data-confirm="Hapus pembayaran ini? Total akan dihitung ulang.">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-xs btn-outline-danger">×</button>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="text-center text-muted">Belum ada pembayaran.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4 grid-margin">
        @can('service_invoice.status')
        <div class="card mb-3">
            <div class="card-body">
                <h6 class="card-title">Status Pengerjaan</h6>

                @if($invoice->isCancelled())
                    <div class="alert alert-danger py-2 mb-0">
                        Dibatalkan {{ $invoice->cancelled_at?->format('d/m/Y H:i') }}
                        oleh {{ $invoice->canceller->name ?? '-' }}.<br>
                        <small>{{ $invoice->cancel_reason }}</small>
                    </div>
                @else
                    <form method="POST" action="{{ route('service.invoice.status', $invoice->id) }}">
                        @csrf
                        <div class="mb-2">
                            <select name="work_status" class="form-select form-select-sm">
                                @foreach(['belum', 'proses', 'selesai'] as $key)
                                    <option value="{{ $key }}" {{ $invoice->work_status === $key ? 'selected' : '' }}>
                                        {{ \App\Models\ServiceInvoice::WORK_STATUS[$key] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <input type="text" name="note" class="form-control form-control-sm" placeholder="Catatan (opsional)">
                        </div>
                        <button class="btn btn-sm btn-primary w-100">Perbarui Status</button>
                    </form>

                    <ul class="list-unstyled small text-muted mt-2 mb-0">
                        <li>Mulai: {{ $invoice->work_started_at?->format('d/m/Y H:i') ?? '—' }}</li>
                        <li>Selesai: {{ $invoice->work_finished_at?->format('d/m/Y H:i') ?? '—' }}</li>
                    </ul>

                    @can('service_invoice.cancel')
                        <hr>
                        <form method="POST" action="{{ route('service.invoice.cancel', $invoice->id) }}"
                              data-confirm="Batalkan invoice ini? Tindakan ini tidak bisa dibalik.">
                            @csrf
                            <input type="text" name="cancel_reason" class="form-control form-control-sm mb-2"
                                   placeholder="Alasan pembatalan" required>
                            <button class="btn btn-sm btn-outline-danger w-100">Batalkan Invoice</button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>
        @endcan

        <div class="card">
            <div class="card-body">
                <h6 class="card-title">Riwayat</h6>
                <ul class="list-unstyled mb-0">
                    @foreach($invoice->logs as $log)
                    <li class="mb-2 pb-2 border-bottom">
                        <div><strong>{{ $log->eventLabel() }}</strong></div>
                        @if($log->from_status || $log->to_status)
                            <div><small class="text-muted">{{ $log->from_status ?? '—' }} → {{ $log->to_status }}</small></div>
                        @endif
                        @if($log->note)<div><small>{{ $log->note }}</small></div>@endif
                        <small class="text-muted">
                            {{ $log->created_at->format('d/m/Y H:i') }} · {{ $log->changedBy->name ?? 'Sistem' }}
                        </small>
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>

@can('service_invoice.payment')
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('service.invoice.payment.store', $invoice->id) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Catat Pembayaran</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label">Tanggal Bayar</label>
                        <input type="date" name="paid_at" class="form-control" value="{{ now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Jenis</label>
                        <select name="type" class="form-select" required>
                            @foreach(\App\Models\ServiceInvoicePayment::TYPES as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">Label untuk cetakan saja — status bayar dihitung dari nominalnya.</small>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Jumlah (Rp)</label>
                        {{-- Aturan global 12: JANGAN echo $invoice->remaining (decimal:2, string
                             "1500000.00") langsung ke input yang nilainya nanti dibersihkan
                             pemisah ribuan oleh controller — titik desimalnya akan terbaca
                             sebagai pemisah ribuan dan angkanya jadi 100x. (int) di sini
                             membuang bagian desimalnya sebelum sampai ke value="". --}}
                        <input type="text" name="amount" class="form-control" required
                               value="{{ max((float) $invoice->remaining, 0) > 0 ? (int) $invoice->remaining : '' }}">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Metode</label>
                        <select name="method" class="form-select" required>
                            @foreach(\App\Models\ServiceInvoicePayment::METHODS as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Referensi</label>
                        <input type="text" name="reference" class="form-control" maxlength="190"
                               placeholder="No. transaksi / rekening pengirim">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Catatan</label>
                        <textarea name="note" class="form-control" rows="2"></textarea>
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
