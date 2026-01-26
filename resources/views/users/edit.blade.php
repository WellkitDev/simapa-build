<div class="modal fade" id="editUserModal{{ $item->id }}" tabindex="-1"
    aria-labelledby="editUserModalLabel{{ $item->id }}" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editUserModalLabel{{ $item->id }}">Step #2: Edit User
                    {{ $item->username }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="{{ route('user.management.update', $item->id) }}" method="POST"
                    enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    <!-- Nama, Username, Email -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="name{{ $item->id }}">Nama Lengkap <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name{{ $item->id }}" name="name"
                                required placeholder="contoh: Budi Santoso" value="{{ old('name', $item->name) }}">
                            @error('name')
                                <small class="text-danger">{{ $message }}</small>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="username{{ $item->id }}">Username <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username{{ $item->id }}" name="username"
                                required placeholder="hanya huruf kecil, angka, _ dan -"
                                value="{{ old('username', $item->username) }}">
                            @error('username')
                                <small class="text-danger">{{ $message }}</small>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="email{{ $item->id }}">Email <span
                                class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email{{ $item->id }}" name="email"
                            required placeholder="contoh: budi@perusahaan.com"
                            value="{{ old('email', $item->email) }}">
                        @error('email')
                            <small class="text-danger">{{ $message }}</small>
                        @enderror
                    </div>

                    <!-- Password -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="password{{ $item->id }}">Password</label>
                            <input type="password" class="form-control" id="password{{ $item->id }}"
                                name="password" autocomplete="new-password" value="{{ old('password') }}">
                            @error('password')
                                <div class="text-danger">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="password_confirmation{{ $item->id }}">Konfirmasi
                                Password</label>
                            <input type="password" class="form-control" id="password_confirmation{{ $item->id }}"
                                name="password_confirmation" placeholder="Ulangi password"
                                value="{{ old('password') }}">
                        </div>
                        <input type="hidden" name="generated_password" id="generated_password{{ $item->id }}">
                    </div>

                    <!-- Role -->
                    <div class="row">
                        <div class="mb-3">
                            <label class="form-label" for="role_id{{ $item->id }}">Role <span
                                    class="text-danger">*</span></label>
                            <select class="form-select select2" name="role_id" id="role_id{{ $item->id }}"
                                required>
                                <option value="" disabled {{ old('role_id') ? '' : 'selected' }}>-- Pilih Role --
                                </option>
                                @foreach ($role as $role)
                                    <option value="{{ $role->id }}"
                                        {{ old('role_id', $user->roles->first()?->id) == $role->id ? 'selected' : '' }}>
                                        {{ ucfirst($role->name) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('role_id')
                                <small class="text-danger">{{ $message }}</small>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_active{{ $item->id }}"
                                    name="is_active" value="1"
                                    {{ old('is_active', $item->is_active) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active{{ $item->id }}">
                                    User Aktif
                                </label>
                            </div>
                            @error('is_active')
                                <small class="text-danger">{{ $message }}</small>
                            @enderror
                        </div>
                    </div>



                    <!-- Checkbox Options -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input"
                                    id="generate_password{{ $item->id }}" name="generate_password"
                                    {{ old('generate_password') ? 'checked' : '' }}>
                                <label class="form-check-label" for="generate_password{{ $item->id }}">
                                    Generate password otomatis
                                </label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="notify_user{{ $item->id }}"
                                    name="notify_user" {{ old('notify_user') ? 'checked' : 'checked' }}>
                                <label class="form-check-label" for="notify_user{{ $item->id }}">
                                    Kirim email notifikasi ke user
                                </label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input"
                                    id="force_password_change{{ $item->id }}" name="force_password_change"
                                    {{ old('force_password_change') ? 'checked' : 'checked' }}>
                                <label class="form-check-label" for="force_password_change{{ $item->id }}">
                                    Paksa ganti password saat login pertama
                                </label>
                            </div>
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input"
                                    id="show_password_{{ $item->id }}">
                                <label class="form-check-label" for="show_password_{{ $item->id }}">Tampilkan
                                    password</label>
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
