@extends('layouts.master')
@section('title', 'Manuscript Tracker - SiMAPA')
@section('content')
<div class="page-content">
    <h4>Manuscript Tracker</h4>
    @foreach($details as $detail)
        <div>{{ $detail->title }}</div>
    @endforeach
</div>
@endsection
