@extends('layouts.master')
@section('title', 'Distribusi Buku - SiMAPA')
@section('content')
<div class="card">
    <div class="card-header bg-transparent border-bottom"><h5 class="mb-0">Distribusi Naskah — Buku</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped" id="tblDistBuku">
                <thead><tr><th>Judul</th><th>Status (bab paling lambat)</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($titles as $t)
                    <tr>
                        <td>{{ $t->title }}</td>
                        <td>{{ $t->manuscriptStatusLabel() ?? '—' }}</td>
                        <td><a href="{{ route('distribusi.buku.show', $t->id) }}" class="btn btn-sm btn-outline-primary">Distribusi</a></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-center text-muted">Belum ada buku.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
