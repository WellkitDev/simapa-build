@extends('layouts.master2')
@section('title', 'Forgot Password - SiMAPA')

@section('content')
    <div class="page-content d-flex align-items-center justify-content-center">
        <div class="row w-100 mx-0 auth-page">
            <div class="col-md-8 col-xl-6 mx-auto">
                <div class="card">
                    <div class="row">
                        <div class="col-md-6 pe-md-0">
                            <div class="auth-side-wrapper"
                                style="background-image: url('{{ asset('assets/images/bg-login.png') }}');">
                            </div>
                        </div>
                        <div class="col-md-6 ps-md-0">
                            <div class="auth-form-wrapper px-4 py-5">
                                <a href="#" class="noble-ui-logo d-block mb-2">Si<span>MAPA</span></a>
                                <h5 class="text-muted fw-normal mb-4">Reset your password to SiMAPA</h5>

                                <form class="forms-sample" method="POST" action="{{ route('password.email') }}">
                                    @csrf
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email address</label>
                                        <input type="email" name="email"
                                            class="form-control @error('email') is-invalid @enderror" id="email"
                                            placeholder="Email" value="{{ old('email') }}" required autofocus>
                                        @error('email')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div>
                                        <button type="submit" class="btn btn-primary me-2 mb-2 mb-md-0">Reset</button>
                                        <a href="{{ route('login') }}" class="btn btn-secondary me-2 mb-2 mb-md-0">Login</a>
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
