@extends('layouts.master')
@section('title', 'Manuscript Tracker - SiMAPA')
@section('content')
<div class="page-content">
    <h4>Manuscript Tracker</h4>
    @foreach($stages as $stage)
        <div>{{ Str::title(str_replace('_', ' ', $stage)) }}</div>
        @foreach(($byStatus[$stage] ?? collect()) as $detail)
            <div data-id="{{ $detail->titleProgress->id }}">{{ $detail->title }}</div>
        @endforeach
    @endforeach
</div>
@endsection
