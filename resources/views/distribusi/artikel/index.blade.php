@extends('layouts.master')
@section('title', 'Distribusi Artikel - SiMAPA')
@section('content')
<div class="card">
    <div class="card-header bg-transparent border-bottom"><h5 class="mb-0">Distribusi Naskah — Artikel</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="tblDistArtikel">
                <thead><tr><th>Judul</th><th>Status</th><th>Editor</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($titles as $t)
                    @php $p = optional($t->orderDetails->map->titleProgress->filter()->first()); @endphp
                    <tr>
                        <td>{{ $t->title }}</td>
                        <td>{{ \App\Models\Title::stageLabel(optional($p)->status) ?? '—' }}</td>
                        <td>{{ optional(optional($p)->assignedUser)->name ?? 'Belum' }}</td>
                        <td><a href="{{ route('distribusi.artikel.show', $t->id) }}" class="btn btn-sm btn-outline-primary">Distribusi</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted">Belum ada artikel.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
