{{-- $s = JournalSubmission|null. Password TIDAK dipopulasi (isi hanya bila mengganti). --}}
<div class="row">
    <div class="col-md-8 mb-2">
        <label class="form-label">Judul Artikel</label>
        <select name="title_id" class="form-select">
            <option value="">— tak tertaut —</option>
            @foreach($articles as $a)
                <option value="{{ $a->id }}" {{ optional($s)->title_id == $a->id ? 'selected' : '' }}>{{ $a->title }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4 mb-2">
        <label class="form-label">Status <span class="text-danger">*</span></label>
        <select name="status" class="form-select">
            @foreach(['submitted' => 'Submitted', 'loa' => 'LoA', 'published' => 'Published'] as $val => $lab)
                <option value="{{ $val }}" {{ (optional($s)->status ?? 'submitted') === $val ? 'selected' : '' }}>{{ $lab }}</option>
            @endforeach
        </select>
    </div>
</div>
<div class="row">
    <div class="col-md-6 mb-2"><label class="form-label">Tgl Submit</label><input type="date" name="tgl_submit" class="form-control" value="{{ optional(optional($s)->tgl_submit)->format('Y-m-d') }}"></div>
    <div class="col-md-6 mb-2"><label class="form-label">Tgl Terbit</label><input type="date" name="tgl_terbit" class="form-control" value="{{ optional(optional($s)->tgl_terbit)->format('Y-m-d') }}"></div>
</div>
<div class="row">
    <div class="col-md-6 mb-2"><label class="form-label">OJS Akun</label><input type="text" name="ojs_akun" class="form-control" value="{{ optional($s)->ojs_akun }}"></div>
    <div class="col-md-6 mb-2"><label class="form-label">OJS Password</label><input type="text" name="ojs_password" class="form-control" placeholder="{{ $s ? 'kosongkan bila tak diubah' : '' }}"></div>
</div>
<div class="row">
    <div class="col-md-6 mb-2"><label class="form-label">File LoA (pdf/gambar)</label><input type="file" name="loa" class="form-control">@if(optional($s)->loa_url)<small><a href="{{ $s->loa_url }}" target="_blank" rel="noopener">LoA saat ini</a></small>@endif</div>
    <div class="col-md-6 mb-2"><label class="form-label">Bukti Bayar</label><input type="file" name="bukti_bayar" class="form-control">@if(optional($s)->bukti_bayar_url)<small><a href="{{ $s->bukti_bayar_url }}" target="_blank" rel="noopener">Bukti saat ini</a></small>@endif</div>
</div>
<div class="mb-2"><label class="form-label">Link Artikel Publish</label><input type="text" name="link_publish" class="form-control" value="{{ optional($s)->link_publish }}"></div>
<div class="mb-0"><label class="form-label">Catatan</label><textarea name="catatan" class="form-control" rows="2">{{ optional($s)->catatan }}</textarea></div>
