@extends('layouts.master2')
@section('title')
    Access Denied
@endsection
@section('content')
    <div class="page-content d-flex align-items-center justify-content-center">
        <div class="row w-100 mx-0 auth-page">
            <div class="col-md-8 col-xl-6 mx-auto d-flex flex-column align-items-center text-center">
                <img src="{{ asset('assets/images/others/access-denie.png') }}" class="img-fluid mb-3 wd-500" alt="403">
                <h1 class="fw-bolder mb-2 mt-2 tx-80 text-muted">403</h1>
                <h4 class="mb-2">Access Denied</h4>
                <h6 class="text-muted mb-4">
                    Sorry, you don't have permission to access this page.
                </h6>
                <a href="{{ route('login') }}" class="btn btn-primary">Back to Home</a>
            </div>
        </div>
    </div>
@endsection
