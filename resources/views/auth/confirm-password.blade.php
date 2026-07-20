@extends('layouts.master2')
@section('title')
    Confirm Password - SiMAPA
@endsection

@section('content')
    <div class="page-content d-flex align-items-center justify-content-center">

        <div class="row w-100 mx-0 auth-page">
            <div class="col-md-8 col-xl-6 mx-auto">
                <div class="card">
                    <div class="row">
                        <div class="col-md-4 pe-md-0">
                            <div class="auth-side-wrapper"
                                style="background-image: url('{{ asset('assets/images/bg-login.png') }}');">

                            </div>
                        </div>
                        <div class="col-md-8 ps-md-0">
                            <div class="auth-form-wrapper px-4 py-5">
                                <a href="#" class="noble-ui-logo d-block mb-2">Si<span>MAPA</span></a>
                                <h5 class="text-muted fw-normal mb-4">Area ini terlindungi. Konfirmasi password kamu untuk
                                    melanjutkan.</h5>
                                <form class="forms-sample" method="POST" action="{{ route('password.confirm') }}">
                                    @csrf
                                    <div class="mb-3">
                                        <label for="password" class="form-label">Password</label>
                                        <input type="password" class="form-control @error('password') is-invalid @enderror"
                                            id="password" name="password" placeholder="Password" required autofocus
                                            autocomplete="current-password">
                                        @error('password')
                                            <span class="invalid-feedback" role="alert">
                                                <strong>{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                    <div>
                                        <button type="submit" class="btn btn-primary me-2 mb-2 mb-md-0">
                                            Konfirmasi
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection
