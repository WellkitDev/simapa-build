{{--
    Pemilik order (kolom "Marketing"). Hanya tampil untuk superadmin — role lain
    tak boleh menentukannya, dan OrderOwnerService mengabaikan field ini dari
    siapa pun selain superadmin walau dikirim lewat POST langsung.

    Satu partial dipakai empat formulir (buat/edit × buku/jurnal) supaya aturannya
    mustahil berbeda antar layar.

    $terpilih = id pemilik saat ini (edit); kosong = default ke diri sendiri (buat).
--}}
@php
    $pemilikSvc = app(\App\Services\OrderOwnerService::class);
@endphp

@if (auth()->check() && $pemilikSvc->bolehMemilih(auth()->user()))
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Pemilik Order (Marketing)</label>
            <select name="user_id" class="form-select @error('user_id') is-invalid @enderror">
                @foreach ($pemilikSvc->pilihan(auth()->user(), $terpilih ?? null) as $u)
                    <option value="{{ $u->id }}"
                        @selected((int) old('user_id', $terpilih ?? auth()->id()) === (int) $u->id)>
                        {{ $u->name }}@if ($u->id === auth()->id()) (saya) @endif
                    </option>
                @endforeach
            </select>
            @error('user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text">
                Menentukan siapa yang menerima capaian target &amp; komisi dari order ini,
                dan siapa yang melihatnya di daftar order.
            </div>
        </div>
    </div>
@endif
