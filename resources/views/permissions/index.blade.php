@extends('layouts.master')
@section('title', 'Hak Akses - SiMAPA')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Hak Akses</h5>
    <form method="GET" class="d-flex align-items-center gap-2">
        <label class="text-muted mb-0" style="font-size:13px">Role</label>
        <select name="role" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            @foreach($roles as $r)
                <option value="{{ $r }}" @selected($r === $selected)>{{ ucfirst($r) }}</option>
            @endforeach
        </select>
    </form>
</div>

@if($locked)
    <div class="alert alert-info">
        Role <strong>{{ ucfirst($selected) }}</strong> selalu memiliki seluruh hak akses dan tidak dapat diubah —
        ini pengaman agar tidak ada yang terkunci dari sistem.
    </div>
@endif

<form method="POST" action="{{ route('permission.update') }}">
    @csrf @method('PUT')
    <input type="hidden" name="role" value="{{ $selected }}">

    <div class="card"><div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead><tr><th style="min-width:220px">Modul</th><th>Hak</th></tr></thead>
                <tbody>
                @foreach($matrix as $module => $def)
                    <tr>
                        <td class="dt-judul">
                            {{ $def['label'] }}
                            <div class="text-muted" style="font-size:11px">{{ $module }}</div>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-3">
                                @foreach($def['actions'] as $action => $permission)
                                    <label class="form-check-label d-flex align-items-center gap-1 mb-0">
                                        <input type="checkbox" class="form-check-input mt-0"
                                               name="permissions[]" value="{{ $permission }}"
                                               @checked($locked || in_array($permission, $granted, true))
                                               @disabled($locked)>
                                        <span>{{ $action }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @unless($locked)
            <button class="btn btn-primary btn-sm mt-2">Simpan</button>
        @endunless
    </div></div>
</form>
@endsection
