@extends('layouts.master')
@section('title', ($mode === 'edit' ? 'Edit' : 'Buat') . ' Slip Gaji - SiMAPA')

@section('content')
@php
    $action = $mode === 'edit' ? route('salary.slip.update', $slip->id) : route('salary.slip.store');
    $oldEarnings = old('earnings', $earnings->map(fn ($l) => ['label' => $l->label, 'amount' => (int) $l->amount])->values()->all());
    $oldDeductions = old('deductions', $deductions->map(fn ($l) => ['label' => $l->label, 'amount' => (int) $l->amount])->values()->all());
@endphp

<div class="row"><div class="col-lg-9">
<div class="card"><div class="card-body">
    <h5 class="mb-3">{{ $mode === 'edit' ? 'Edit' : 'Buat' }} Slip Gaji</h5>

    <form method="POST" action="{{ $action }}" id="slipForm">
        @csrf
        @if($mode === 'edit') @method('PUT') @endif
        @idempotent

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label">Karyawan</label>
                <select name="user_id" class="form-select @error('user_id') is-invalid @enderror" required>
                    <option value="">— pilih —</option>
                    @foreach ($employees as $emp)
                        <option value="{{ $emp->id }}" @selected(old('user_id', $slip->user_id) == $emp->id)>{{ $emp->name }}</option>
                    @endforeach
                </select>
                @error('user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label">Bulan</label>
                <select name="period_month" class="form-select" required>
                    @foreach (\App\Models\SalarySlip::MONTHS as $num => $label)
                        <option value="{{ $num }}" @selected(old('period_month', $slip->period_month) == $num)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tahun</label>
                <input type="number" name="period_year" value="{{ old('period_year', $slip->period_year) }}" min="2000" max="2100" class="form-control" required>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-2">
            <h6 class="mb-0">Rincian Penghasilan</h6>
            <div>
                <button type="button" class="btn btn-xs btn-outline-secondary" onclick="addRow('earnings')">+ Baris</button>
                <button type="button" class="btn btn-xs btn-outline-primary" onclick="fillPreset('earnings')">Preset</button>
            </div>
        </div>
        @error('earnings')<div class="text-danger small">{{ $message }}</div>@enderror
        <table class="table table-sm mt-2" id="earnings-table">
            <thead><tr><th>Komponen</th><th style="width:190px">Nominal (Rp)</th><th style="width:40px"></th></tr></thead>
            <tbody></tbody>
        </table>

        <div class="d-flex justify-content-between align-items-center mt-3">
            <h6 class="mb-0">Rincian Potongan</h6>
            <div>
                <button type="button" class="btn btn-xs btn-outline-secondary" onclick="addRow('deductions')">+ Baris</button>
                <button type="button" class="btn btn-xs btn-outline-primary" onclick="fillPreset('deductions')">Preset</button>
            </div>
        </div>
        <table class="table table-sm mt-2" id="deductions-table">
            <thead><tr><th>Komponen</th><th style="width:190px">Nominal (Rp)</th><th style="width:40px"></th></tr></thead>
            <tbody></tbody>
        </table>

        <div class="row mt-2">
            <div class="col-md-5 ms-auto">
                <table class="table table-sm">
                    <tr><td>Total Penghasilan</td><td class="text-end" id="sum-earnings">Rp 0</td></tr>
                    <tr><td>Total Potongan</td><td class="text-end" id="sum-deductions">Rp 0</td></tr>
                    <tr class="fw-bold"><td>Gaji Bersih</td><td class="text-end" id="sum-net">Rp 0</td></tr>
                </table>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Catatan (opsional)</label>
            <textarea name="note" rows="2" class="form-control">{{ old('note', $slip->note) }}</textarea>
        </div>

        <button class="btn btn-primary">{{ $mode === 'edit' ? 'Simpan Perubahan' : 'Simpan Slip' }}</button>
        <a href="{{ route('salary.slip.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>
</div></div>
</div></div>
@endsection

@push('custom-scripts')
<script>
const INIT = {
    earnings: @json(array_values($oldEarnings ?: [['label' => 'Gaji Pokok', 'amount' => 0]])),
    deductions: @json(array_values($oldDeductions ?: [])),
};
const PRESET = {
    earnings: [
        { label: 'Gaji Pokok', amount: 0 },
        { label: 'Tunjangan Jabatan', amount: 0 },
        { label: 'Tunjangan Transport', amount: 0 },
    ],
    deductions: [
        { label: 'BPJS', amount: 0 },
        { label: 'PPh21', amount: 0 },
    ],
};
const rp = n => 'Rp ' + (Number(n) || 0).toLocaleString('id-ID');

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function rowHtml(group, label, amount) {
    const safe = escapeHtml(label);
    return `<tr>
        <td><input type="text" name="${group}[__i__][label]" class="form-control form-control-sm" value="${safe}" required></td>
        <td><input type="number" name="${group}[__i__][amount]" class="form-control form-control-sm amount" min="0" step="1" value="${amount || 0}" required></td>
        <td><button type="button" class="btn btn-xs btn-outline-danger" onclick="delRow(this)">&times;</button></td>
    </tr>`;
}

function reindex(group) {
    const body = document.querySelector('#' + group + '-table tbody');
    [...body.querySelectorAll('tr')].forEach((tr, i) => {
        tr.querySelectorAll('input').forEach(inp => {
            inp.name = inp.name.replace(/\[(?:\d+|__i__)\]/, '[' + i + ']');
        });
    });
}

function addRow(group, label = '', amount = 0) {
    const body = document.querySelector('#' + group + '-table tbody');
    body.insertAdjacentHTML('beforeend', rowHtml(group, label, amount));
    reindex(group);
    recalc();
}

function delRow(btn) {
    const tr = btn.closest('tr');
    const group = tr.closest('table').id.replace('-table', '');
    tr.remove();
    reindex(group);
    recalc();
}

function fillPreset(group) {
    PRESET[group].forEach(r => addRow(group, r.label, r.amount));
}

function sumGroup(group) {
    return [...document.querySelectorAll('#' + group + '-table tbody .amount')]
        .reduce((s, i) => s + (Number(i.value) || 0), 0);
}

function recalc() {
    const e = sumGroup('earnings'), d = sumGroup('deductions');
    document.getElementById('sum-earnings').textContent = rp(e);
    document.getElementById('sum-deductions').textContent = rp(d);
    document.getElementById('sum-net').textContent = rp(e - d);
}

document.addEventListener('input', ev => { if (ev.target.classList.contains('amount')) recalc(); });

INIT.earnings.forEach(r => addRow('earnings', r.label, r.amount));
INIT.deductions.forEach(r => addRow('deductions', r.label, r.amount));
if (document.querySelector('#earnings-table tbody').children.length === 0) addRow('earnings', 'Gaji Pokok', 0);
recalc();
</script>
@endpush
