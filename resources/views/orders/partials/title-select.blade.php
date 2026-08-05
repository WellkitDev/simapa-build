{{--
  Dropdown judul bersama untuk KEEMPAT form order (buku/jurnal × create/edit).
  Menggantikan markup + JS yang sebelumnya tersalin empat kali.

  Aturan yang dikunci:
  · <option> berisi JUDUL SAJA. Kode / bidang ilmu / indeksasi hanya keterangan
    visual lewat data-* + templateResult, sehingga string "KODE — Judul" tidak
    pernah bisa ikut tersimpan ke tb_order_details.title.
  · Judul baru dikirim berprefix "new:" agar TitleService::resolveForOrder() tidak
    perlu menebak lewat is_numeric() — judul bernama "2026" dulu salah dibaca id.
  · insertTag menaruh opsi "buat baru" di BAWAH hasil pencarian. Default Select2 4.x
    menaruhnya di ATAS, sehingga ketik-lalu-Enter cenderung membuat judul kembar
    alih-alih memilih judul yang sudah ada.
  · Class-nya "title-select", BUKAN "select2": assets/js/select2.js meng-init semua
    .select2 sendiri, dan init ganda membuat konfigurasi di bawah terabaikan.

  Parameter:
    $titles    Collection<Title>   daftar judul yang boleh dipilih (sudah difilter controller)
    $selected  string|int|null     id judul terpilih, ATAU teks judul (data lama / prefill tagihan)
--}}
@php
    $selected = $selected ?? null;
    $selectedIsId = is_numeric($selected) && $titles->contains('id', (int) $selected);
@endphp

<select name="title_id" id="title_id" class="form-select title-select" required>
    <option value="">Pilih judul disetujui / ketik judul baru</option>
    @foreach ($titles as $t)
        <option value="{{ $t->id }}"
            data-code="{{ $t->code }}"
            data-scope="{{ $t->scope?->scope }}"
            data-tipe-naskah="{{ $t->tipe_naskah }}"
            data-scope-id="{{ $t->scope_id }}"
            data-indeksasi="{{ $t->indeksasi }}"
            {{ (string) $selected === (string) $t->id ? 'selected' : '' }}>{{ $t->title }}</option>
    @endforeach

    @if (filled($selected) && ! $selectedIsId)
        {{-- Judul lama yang belum tertaut Title, atau prefill dari tagihan: dikirim
             sebagai judul baru yang eksplisit, bukan string polos yang ambigu. --}}
        <option value="new:{{ $selected }}" selected>{{ $selected }}</option>
    @endif
</select>
<small class="text-muted">Pilih dari daftar judul disetujui, atau ketik judul baru bila belum ada.</small>

@push('custom-scripts')
<script>
    $(function () {
        var $sel = $('#title_id');
        if (!$sel.length) return;

        function metaOf(state) {
            if (!state.element || !state.element.dataset) return null;
            var d = state.element.dataset;
            var bits = [];
            if (d.code) bits.push(d.code);
            if (d.scope) bits.push(d.scope);
            if (d.indeksasi) bits.push(d.indeksasi);
            return bits.length ? bits.join(' · ') : null;
        }

        $sel.select2({
            tags: true,
            width: '100%',
            createTag: function (params) {
                var term = $.trim(params.term);
                if (term === '') return null;
                return { id: 'new:' + term, text: term, newTag: true };
            },
            insertTag: function (data, tag) { data.push(tag); },
            templateResult: function (state) {
                if (!state.id) return state.text;
                var $row = $('<span></span>').text(state.text);
                var sub = metaOf(state);
                if (sub) $row.append($('<small class="d-block text-muted"></small>').text(sub));
                return $row;
            },
            templateSelection: function (state) { return state.text; },
        });
    });
</script>
@endpush
