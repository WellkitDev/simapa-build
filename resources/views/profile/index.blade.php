@extends('layouts.master')
<!-- Title pages active -->
@section('title', 'Profile - SiMAPA')

@push('plugin-styles')
    <!-- Plugin css import here -->
@endpush

@section('content')
    <!-- Page content here -->
    <div class="row">
        <div class="col-12 grid-margin">
            <div class="card">
                <div class="position-relative">
                    <figure class="overflow-hidden mb-0 d-flex justify-content-center">
                        <img src="{{ asset('assets/images/others/bg-profile.jpg') }}"class="rounded-top" alt="profile cover">
                    </figure>
                    <div
                        class="d-flex justify-content-between align-items-center position-absolute top-90 w-100 px-2 px-md-4 mt-n4">
                        <div>
                            @if (!Auth::user()->profile->profile_picture_url)
                                <img class="wd-70 rounded-circle" src="{{ asset('assets/images/others/user.svg') }}"
                                    alt="profile">
                            @else
                                <img class="wd-70 rounded-circle"
                                    src="{{ route('profile.image', Auth::user()->profile->profile_picture_id) }}"
                                    alt="profile" style="object-fit: cover;">
                            @endif

                            <span class="h4 ms-3 text-dark">{{ Auth::user()->name }}</span>
                        </div>
                        <div class="d-none d-md-block">
                            <button class="btn btn-primary btn-icon-text">
                                <i data-feather="smile" class="btn-icon-prepend"></i> Aktif
                            </button>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-center p-3 rounded-bottom">
                    <ul class="d-flex align-items-center m-0 p-0">

                        <li class="ms-3 ps-3 border-start d-flex align-items-center active">
                            <i class="me-1 icon-md" data-feather="user"></i>
                            <a class="pt-1px d-none d-md-block text-body" href="#">About</a>
                        </li>
                        <li class="ms-3 ps-3 border-start d-flex align-items-center">
                            <i class="me-1 icon-md" data-feather="users"></i>
                            <a class="pt-1px d-none d-md-block text-body" href="#">Friends <span
                                    class="text-muted tx-12">3,765</span></a>
                        </li>
                        <li class="ms-3 ps-3 border-start d-flex align-items-center">
                            <i class="me-1 icon-md" data-feather="image"></i>
                            <a class="pt-1px d-none d-md-block text-body" href="#">Photos</a>
                        </li>
                        <li class="ms-3 ps-3 border-start d-flex align-items-center">
                            <i class="me-1 icon-md" data-feather="video"></i>
                            <a class="pt-1px d-none d-md-block text-body" href="#">Videos</a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <div class="row profile-body">
        <!-- left wrapper start -->
        <div class="d-none d-md-block col-md-4 col-xl-3 left-wrapper">
            <div class="card rounded">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h6 class="card-title mb-0">Address</h6>
                        <div class="dropdown">
                            <button class="btn btn-link p-0" type="button" id="dropdownMenuButton"
                                data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="icon-lg text-muted pb-3px" data-feather="more-horizontal"></i>
                            </button>
                            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton">
                                <a class="dropdown-item d-flex align-items-center" href="javascript:;"><i
                                        data-feather="edit-2" class="icon-sm me-2"></i> <span class="">Edit</span></a>
                                <a class="dropdown-item d-flex align-items-center" href="javascript:;"><i
                                        data-feather="git-branch" class="icon-sm me-2"></i> <span
                                        class="">Update</span></a>
                                <a class="dropdown-item d-flex align-items-center" href="javascript:;"><i data-feather="eye"
                                        class="icon-sm me-2"></i> <span class="">View all</span></a>
                            </div>
                        </div>
                    </div>
                    <p>Kota Jambi, Jambi, Indonesia
                    </p>
                    <div class="mt-3">
                        <label class="tx-11 fw-bolder mb-0 text-uppercase">Username:</label>
                        <p class="text-muted">{{ Auth::user()->username }}</p>
                    </div>
                    <div class="mt-3">
                        <label class="tx-11 fw-bolder mb-0 text-uppercase">Joined:</label>
                        <p class="text-muted">{{ Auth::user()->profile()->created_at ?? '-' }}</p>
                    </div>
                    <div class="mt-3">
                        <label class="tx-11 fw-bolder mb-0 text-uppercase">Departement:</label>
                        <p class="text-muted">IT</p>
                    </div>
                    <div class="mt-3">
                        <label class="tx-11 fw-bolder mb-0 text-uppercase">Email:</label>
                        <p class="text-muted">{{ Auth::user()->email }}</p>
                    </div>
                    <div class="mt-3">
                        <label class="tx-11 fw-bolder mb-0 text-uppercase">Status:</label>
                        @if (Auth::user()->is_active == true)
                            <p class="text-muted">Aktif</p>
                        @else
                            <p class="text-muted">Disable</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- left wrapper end -->
        <!-- middle wrapper start -->
        <div class="col-md-8 col-xl-6 middle-wrapper">
            <div class="row">
                <div class="col-md-12 grid-margin">
                    <div class="card rounded">
                        <div class="card-header">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center">
                                    @if (!Auth::user()->profile->profile_picture_url)
                                        <img class="img-xs rounded-circle"
                                            src="{{ asset('assets/images/others/user.svg') }}" alt="profile">
                                    @else
                                        <img class="img-xs rounded-circle"
                                            src="{{ route('profile.image', Auth::user()->profile->profile_picture_id) }}"
                                            alt="profile" style="object-fit: cover;">
                                    @endif
                                    <div class="ms-2">
                                        <h5>Password Confirmation</h5>
                                        <p class="tx-11 text-muted">
                                            {{ Auth::user()->profile?->updated_at?->format('H:i d M Y') ?? '-' }}</p>
                                    </div>
                                </div>

                            </div>
                        </div>
                        <div class="card-body">
                            <p class="mb-4 tx-14 text-muted">Pastikan password baru kuat dan unik.</p>

                            <form method="POST" action="{{ route('password.update') }}" class="forms-sample">
                                @csrf
                                @method('PUT')

                                <!-- Current Password -->
                                <div class="mb-3">
                                    <label for="update_password_current_password" class="form-label">
                                        Password Saat Ini <span class="text-danger">*</span>
                                    </label>
                                    <div class="position-relative">
                                        <input type="password"
                                            class="form-control @error('current_password', 'updatePassword') is-invalid @enderror"
                                            id="update_password_current_password" name="current_password"
                                            autocomplete="current-password" placeholder="Masukkan password lama">
                                        <button type="button"
                                            class="btn btn-link position-absolute end-0 top-50 translate-middle-y pe-3 text-muted"
                                            onclick="togglePassword('update_password_current_password', this)">
                                            <i class="ti ti-eye"></i>
                                        </button>
                                    </div>
                                    @error('current_password', 'updatePassword')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- New Password -->
                                <div class="mb-3">
                                    <label for="update_password_password" class="form-label">
                                        Password Baru <span class="text-danger">*</span>
                                    </label>
                                    <div class="position-relative">
                                        <input type="password"
                                            class="form-control @error('password', 'updatePassword') is-invalid @enderror"
                                            id="update_password_password" name="password" autocomplete="new-password"
                                            placeholder="Min. 8 karakter">
                                        <button type="button"
                                            class="btn btn-link position-absolute end-0 top-50 translate-middle-y pe-3 text-muted"
                                            onclick="togglePassword('update_password_password', this)">
                                            <i class="ti ti-eye"></i>
                                        </button>
                                    </div>
                                    @error('password', 'updatePassword')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Confirm Password -->
                                <div class="mb-4">
                                    <label for="update_password_password_confirmation" class="form-label">
                                        Konfirmasi Password Baru <span class="text-danger">*</span>
                                    </label>
                                    <div class="position-relative">
                                        <input type="password"
                                            class="form-control @error('password_confirmation', 'updatePassword') is-invalid @enderror"
                                            id="update_password_password_confirmation" name="password_confirmation"
                                            autocomplete="new-password" placeholder="Ulangi password baru">
                                        <button type="button"
                                            class="btn btn-link position-absolute end-0 top-50 translate-middle-y pe-3 text-muted"
                                            onclick="togglePassword('update_password_password_confirmation', this)">
                                            <i class="ti ti-eye"></i>
                                        </button>
                                    </div>
                                    @error('password_confirmation', 'updatePassword')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Submit & Success Message -->
                                <div class="d-flex align-items-center gap-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="ti ti-device-floppy me-1"></i> Simpan Perubahan
                                    </button>

                                    @if (session('status') === 'password-updated')
                                        <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                                            class="text-success fw-medium">
                                            <i class="ti ti-check"></i> Password berhasil diperbarui!
                                        </div>
                                    @endif
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12 grid-margin">
                    <div class="card rounded">
                        <div class="card-header">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center">
                                    @if (!Auth::user()->profile->profile_picture_url)
                                        <img class="img-xs rounded-circle"
                                            src="{{ asset('assets/images/others/user.svg') }}" alt="profile">
                                    @else
                                        <img class="img-xs rounded-circle"
                                            src="{{ route('profile.image', Auth::user()->profile->profile_picture_id) }}"
                                            alt="profile">
                                    @endif
                                    <div class="ms-2">
                                        <h5>Profile Information</h5>
                                        <p class="tx-11 text-muted">
                                            {{ Auth::user()->profile?->updated_at?->format('H:i d M Y') ?? '-' }}</p>
                                    </div>
                                </div>

                            </div>
                        </div>
                        <div class="card-body">
                            <p class="mb-3 tx-14">Update your personal profile details.</p>
                            <form class="forms-sample" action="{{ route('profile') }}" method="POST"
                                enctype="multipart/form-data">
                                @csrf
                                @method('PATCH')
                                <div class="mb-3">
                                    <label for="name" class="form-label">Name</label>
                                    <input type="text" class="form-control @error('name') is-invalid @enderror"
                                        id="name" name="name" autocomplete="off" placeholder="name"
                                        value="{{ old('name', Auth::user()->name) }}">
                                    @error('name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control @error('username') is-invalid @enderror"
                                        id="username" name="username" autocomplete="off" placeholder="Username"
                                        value="{{ old('username', Auth::user()->username) }}">
                                    @error('username')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email address</label>
                                    <input type="email" class="form-control @error('email') is-invalid @enderror"
                                        id="email" name="email" placeholder="Email"
                                        value="{{ old('email', Auth::user()->email) }}">
                                    @error('email')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label for="phone_number" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control @error('phone_number') is-invalid @enderror"
                                        id="phone_number" name="phone_number" autocomplete="off"
                                        placeholder="phone_number"
                                        value="{{ old('phone_number', Auth::user()->profile?->phone_number) }}">
                                    @error('phone_number')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label for="birth_date" class="form-label">Birth Date</label>
                                    <input type="text" class="form-control @error('birth_date') is-invalid @enderror"
                                        id="birth_date" name="birth_date" autocomplete="off" placeholder="birth_date"
                                        value="{{ old('birth_date', Auth::user()->profile?->birth_date) }}">
                                    @error('birth_date')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label for="job_description" class="form-label">Deskription</label>
                                    <input type="text"
                                        class="form-control @error('job_description') is-invalid @enderror"
                                        id="job_description" name="job_description" placeholder="job_description"
                                        value="{{ old('job_description', Auth::user()->profile?->job_description) }}">
                                    @error('job_description')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label for="job_name" class="form-label">Job Detail</label>
                                    <input type="text" class="form-control @error('job_name') is-invalid @enderror"
                                        id="job_name" name="job_name" placeholder="job_name"
                                        value="{{ old('job_name', Auth::user()->profile?->job_name) }}">
                                    @error('job_name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label for="address" class="form-label">Address</label>
                                    <input type="text" class="form-control @error('address') is-invalid @enderror"
                                        id="address" name="address" placeholder="address"
                                        value="{{ old('address', Auth::user()->profile?->address) }}">
                                    @error('address')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="mb-3">
                                    <label for="profile_picture" class="form-label">Profile Picture</label>
                                    <input type="file"
                                        class="form-control @error('profile_picture') is-invalid @enderror"
                                        id="profile_picture" name="profile_picture">
                                    @error('profile_picture')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <!-- Preview Area -->
                                    <div class="mt-3 text-center">
                                        <!-- Preview Foto Saat Ini (dari DB) -->
                                        @if (Auth::user()->profile?->profile_picture_url)
                                            <div class="position-relative d-inline-block">
                                                <img id="current-preview"
                                                    src="{{ route('profile.image', Auth::user()->profile->profile_picture_id) }}"
                                                    alt="Foto Profil Saat Ini"
                                                    class="img-thumbnail rounded-circle shadow-sm"
                                                    style="width: 120px; height: 120px; object-fit: cover;"
                                                    loading="lazy">

                                                <small class="text-muted d-block mt-1">Foto saat ini</small>
                                            </div>
                                        @else
                                            <div class="d-inline-block text-center">
                                                <div class="bg-light border rounded-circle d-flex align-items-center justify-content-center mx-auto shadow-sm"
                                                    style="width: 120px; height: 120px;">
                                                    <i class="bi bi-person-fill text-secondary"
                                                        style="font-size: 48px;"></i>
                                                </div>
                                                <small class="text-muted d-block mt-1">Belum ada foto</small>
                                            </div>
                                        @endif

                                        <!-- Preview Foto Baru (real-time) -->
                                        <div id="new-preview-container" class="mt-3 d-none">
                                            <p class="text-success small mb-1"><i class="bi bi-arrow-down"></i> Pratinjau
                                                foto baru:</p>
                                            <img id="new-preview" src="#" alt="Pratinjau"
                                                class="img-thumbnail rounded-circle"
                                                style="width: 120px; height: 120px; object-fit: cover;">
                                        </div>

                                        <!-- Info -->
                                        <small class="text-muted d-block mt-2">
                                            Format: JPG, PNG, GIF. Maks: 2MB.
                                        </small>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary me-2">Save</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- middle wrapper end -->


    </div>
@endsection

@push('plugin-scripts')
    <!-- Plugin js import here -->
@endpush

@push('custom-scripts')
    <!-- Custom js here -->
    <script>
        document.getElementById('profile_picture').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const newPreview = document.getElementById('new-preview');
            const container = document.getElementById('new-preview-container');

            if (file) {
                // Validasi ukuran (2MB)
                if (file.size > 2 * 1024 * 1024) {
                    alert('Ukuran file maksimal 2MB!');
                    e.target.value = '';
                    container.classList.add('d-none');
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(event) {
                    newPreview.src = event.target.result;
                    container.classList.remove('d-none');
                };
                reader.readAsDataURL(file);
            } else {
                container.classList.add('d-none');
            }
        });

        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('ti-eye', 'ti-eye-off');
            } else {
                input.type = 'password';
                icon.classList.replace('ti-eye-off', 'ti-eye');
            }
        }
    </script>
@endpush
