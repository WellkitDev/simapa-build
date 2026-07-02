@extends('layouts.master')
@section('content')
@foreach($journals as $j){{ $j->nama }} @endforeach
@endsection
