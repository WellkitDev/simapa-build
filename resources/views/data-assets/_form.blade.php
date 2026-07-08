@php $isFile = old('type', optional($asset)->type ?? 'link') === 'file'; @endphp
<div class="mb-3"><label class="form-label">Nama <span class="text-danger">*</span></label>
    <input name="name" value="{{ old('name', optional($asset)->name) }}" class="form-control @error('name') is-invalid @enderror" required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="mb-3"><label class="form-label">Deskripsi</label>
    <textarea name="description" rows="2" class="form-control">{{ old('description', optional($asset)->description) }}</textarea>
</div>
<div class="mb-3"><label class="form-label d-block">Jenis</label>
    @foreach (\App\Models\DataAsset::TYPES as $k => $v)
        <label class="me-3"><input type="radio" name="type" value="{{ $k }}" {{ old('type', optional($asset)->type ?? 'link') === $k ? 'checked' : '' }} onclick="toggleSrc('{{ $k }}')"> {{ $v }}</label>
    @endforeach
</div>
<div class="mb-3 src-link" style="{{ $isFile ? 'display:none' : '' }}">
    <label class="form-label">Link Google Sheets / URL @if(!$asset)<span class="text-danger">*</span>@endif</label>
    <input name="url" value="{{ old('url', optional($asset)->url) }}" class="form-control @error('url') is-invalid @enderror" placeholder="https://docs.google.com/spreadsheets/...">
    @error('url')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>
<div class="mb-3 src-file" style="{{ $isFile ? '' : 'display:none' }}">
    <label class="form-label">File Excel/CSV (.xlsx, .xls, .csv) @if(!$asset)<span class="text-danger">*</span>@endif</label>
    <input type="file" name="file" accept=".xlsx,.xls,.csv" class="form-control @error('file') is-invalid @enderror">
    @error('file')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    @if ($asset && $asset->file_name)<small class="text-muted">File saat ini: {{ $asset->file_name }} — kosongkan bila tak ingin ganti.</small>@endif
</div>
<div class="row g-2 mb-3">
    <div class="col-md-4"><label class="form-label">Visibilitas</label>
        <select name="visibility" class="form-select">
            @foreach (\App\Models\DataAsset::VISIBILITIES as $k => $v)<option value="{{ $k }}" {{ old('visibility', optional($asset)->visibility ?? 'private') === $k ? 'selected' : '' }}>{{ $v }}</option>@endforeach
        </select>
    </div>
    <div class="col-md-8"><label class="form-label">Dibagikan ke role (kosong = semua)</label>
        <div class="d-flex gap-2 flex-wrap">
            @foreach (\Spatie\Permission\Models\Role::pluck('name') as $rn)
                <label class="small mb-0"><input type="checkbox" name="shared_roles[]" value="{{ $rn }}" {{ in_array($rn, old('shared_roles', (array) optional($asset)->shared_roles)) ? 'checked' : '' }}> {{ ucfirst($rn) }}</label>
            @endforeach
        </div>
    </div>
</div>
<script>
    function toggleSrc(t) {
        document.querySelectorAll('.src-link').forEach(e => e.style.display = t === 'link' ? '' : 'none');
        document.querySelectorAll('.src-file').forEach(e => e.style.display = t === 'file' ? '' : 'none');
    }
</script>
