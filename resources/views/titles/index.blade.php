@extends('layouts.master')
@section('content')
@foreach($titles as $t){{ $t->title }} @endforeach
@endsection
