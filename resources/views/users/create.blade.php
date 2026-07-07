<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addUserModalLabel">Step #1: Tambah User Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="{{ route('user.management.store') }}" method="POST" enctype="multipart/form-data"
                    id="userForm">
                    @csrf

                    <!-- Nama, Username, Email -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="name">Nama Lengkap <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required
                                placeholder="contoh: Budi Santoso" value="{{ old('name') }}">
                            @error('name')
                                <small class="text-danger">{{ $message }}</small>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" required
                                placeholder="hanya huruf kecil, angka, _ dan -" value="{{ old('username') }}">
                            @error('username')
                                <small class="text-danger">{{ $message }}</small>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="email">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" required
                            placeholder="contoh: budi@perusahaan.com" value="{{ old('email') }}">
                        @error('email')
                            <small class="text-danger">{{ $message }}</small>
                        @enderror
                    </div>

                    <!-- Password -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="password">Password</label>
                            <input type="password" class="form-control" id="password" name="password"
                                placeholder="Min. 8 karakter" autocomplete="new-password">
                            @error('password')
                                <div class="text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="password_confirmation">Konfirmasi Password</label>
                            <input type="password" class="form-control" id="password_confirmation"
                                name="password_confirmation" placeholder="Ulangi password">
                        </div>
                        <input type="hidden" name="generated_password" id="generated_password">
                    </div>

                    <!-- Role -->
                    <div class="mb-3">
                        <label class="form-label" for="role_id">Peran <span class="text-danger">*</span></label>
                        <select class="form-select select2" name="role_id" id="role_id" required>
                            <option value="" disabled {{ old('role_id') ? '' : 'selected' }}>-- Pilih Role --
                            </option>
                            @foreach ($role as $role)
                                <option value="{{ $role->id }}"
                                    {{ old('role_id') == $role->id ? 'selected' : '' }}>
                                    {{ ucfirst($role->name) }}
                                </option>
                            @endforeach
                        </select>
                        @error('role_id')
                            <small class="text-danger">{{ $message }}</small>
                        @enderror
                    </div>

                    <!-- Checkbox Options -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="generate_password"
                                    name="generate_password" {{ old('generate_password') ? 'checked' : '' }}>
                                <label class="form-check-label" for="generate_password">
                                    Generate password otomatis
                                </label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="notify_user" name="notify_user"
                                    {{ old('notify_user') ? 'checked' : 'checked' }}>
                                <label class="form-check-label" for="notify_user">
                                    Kirim email notifikasi ke user
                                </label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="force_password_change"
                                    name="force_password_change"
                                    {{ old('force_password_change') ? 'checked' : 'checked' }}>
                                <label class="form-check-label" for="force_password_change">
                                    Paksa ganti password saat login pertama
                                </label>
                            </div>
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="show_password_create">
                                <label class="form-check-label" for="show_password_create">Tampilkan password</label>
                            </div>
                        </div>
                    </div>

                    <!-- More Details (Collapsible) -->
                    <div class="mb-3">
                        <button type="button" class="btn btn-link p-0" data-bs-toggle="collapse"
                            data-bs-target="#moreDetails">+ Detail Tambahan (Opsional)</button>
                        <div id="moreDetails" class="collapse mt-2">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="phone_number">No. Telepon</label>
                                    <input type="text" class="form-control" id="phone_number" name="phone_number"
                                        placeholder="contoh: 628123456789" value="{{ old('phone_number') }}">
                                    @error('phone_number')
                                        <small class="text-danger">{{ $message }}
                                        </small>
                                    @enderror
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="birth_date">Tanggal Lahir</label>
                                        <input type="date" class="form-control" id="birth_date" name="birth_date"
                                            value="{{ old('birth_date') }}">
                                        @error('birth_date')
                                            <small class="text-danger">{{ $message }}</small>
                                        @enderror
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="address">Alamat</label>
                                    <textarea class="form-control" id="address" name="address" rows="2"
                                        placeholder="contoh: Jl. Sudirman No. 123, Jakarta">{{ old('address') }}</textarea>
                                    @error('address')
                                        <small class="text-danger">{{ $message }}</small>
                                    @enderror
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="job_title">Jabatan</label>
                                        <input type="text" class="form-control" id="job_title" name="job_title"
                                            placeholder="contoh: Staff Marketing" value="{{ old('job_title') }}">
                                        @error('job_title')
                                            <small class="text-danger">{{ $message }}</small>
                                        @enderror
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="department">Departemen</label>
                                        <input type="text" class="form-control" id="department" name="department"
                                            placeholder="contoh: IT" value="{{ old('department') }}">
                                        @error('department')
                                            <small class="text-danger">{{ $message }}</small>
                                        @enderror
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="profile_picture">Foto Profil</label>
                                    <input type="file" class="form-control" id="profile_picture"
                                        name="profile_picture" accept="image/jpeg,image/png,image/jpg,image/gif">
                                    @error('profile_picture')
                                        <small class="text-danger">{{ $message }}</small>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tombol -->
                    <div class="d-flex justify-content-end gap-2">
                        <button type="submit" class="btn btn-primary">Simpan User</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
