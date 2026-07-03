@extends('layouts.master')
@section('title', 'Arsip Judul - SiMAPA')
@section('content')
<h5 class="mb-3">Arsip Judul</h5>
<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <ul class="list-unstyled mb-0">
        @foreach($approved as $t)
            <li><a href="{{ route('archive.show', $t->id) }}">{{ $t->title }}</a></li>
        @endforeach
    </ul>
</div></div></div></div>
@endsection
