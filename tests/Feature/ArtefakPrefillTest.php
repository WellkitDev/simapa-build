<?php

namespace Tests\Feature;

use App\Models\BookIsbn;
use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Models\TitleArchiveArtifact;
use App\Services\GoogleDriveService;
use App\Services\TitleArchivalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArtefakPrefillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function buku(): Title
    {
        return Title::create(['title' => 'Buku Artefak', 'jenis' => 'buku',
                              'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    private function berkas(Title $t, string $slot, string $url, string $status = 'selesai', int $versi = 1): void
    {
        ManuscriptFile::create([
            'title_id' => $t->id, 'slot' => $slot, 'status' => $status, 'version' => $versi,
            'original_name' => $slot . '.pdf', 'drive_url' => $url,
        ]);
    }

    /** @return array<string,array> artefak dikunci per key */
    private function artefak(Title $t): array
    {
        return collect(app(TitleArchivalService::class)->defaultArtifacts($t->fresh()))
            ->keyBy('key')->all();
    }

    /** @test */
    public function berkas_isbn_mengisi_artefak_buku(): void
    {
        $t = $this->buku();
        $this->berkas($t, 'barcode_isbn', 'https://drive.test/barcode');
        $this->berkas($t, 'sertifikat_hki', 'https://drive.test/hki');
        $this->berkas($t, 'ebook', 'https://drive.test/ebook');

        $a = $this->artefak($t);

        $this->assertSame('https://drive.test/barcode', $a['barcode_file']['value']);
        $this->assertSame('https://drive.test/hki', $a['hki_file']['value']);
        $this->assertSame('https://drive.test/ebook', $a['final_book_file']['value']);
    }

    /** @test */
    public function link_terbit_mengisi_artefak_publish_link_buku(): void
    {
        $t = $this->buku();
        BookIsbn::create(['title_id' => $t->id, 'status' => 'cetak',
                          'no_isbn' => '978-000', 'link_terbit' => 'https://toko.test/buku']);

        $a = $this->artefak($t);

        $this->assertSame('978-000', $a['isbn']['value']);
        $this->assertSame('https://toko.test/buku', $a['publish_link']['value']);
    }

    /**
     * publish_link harus lewat linkTerbit(), bukan cabang jenis buatan sendiri: link
     * yang diisi lewat form Informasi Publikasi tersimpan di judul, dan arsip tak boleh
     * berkata "belum diisi" untuk data yang jelas ada.
     *
     * @test
     */
    public function link_di_kolom_judul_ikut_terbaca_artefak(): void
    {
        $t = $this->buku();
        $t->update(['link_terbit' => 'https://judul.test/dari-panel']);

        $this->assertSame('https://judul.test/dari-panel', $this->artefak($t)['publish_link']['value']);
    }

    /**
     * Berkas yang masih ANTRE belum punya URL Drive. Menghitungnya sebagai "sudah ada"
     * membuat arsip mengaku memegang berkas yang belum mendarat — kesalahan yang sudah
     * pernah ditambal di validasi ISBN.
     *
     * @test
     */
    public function berkas_antre_tidak_dihitung(): void
    {
        $t = $this->buku();
        $this->berkas($t, 'barcode_isbn', '', 'antre');

        $this->assertNull($this->artefak($t)['barcode_file']['value']);
    }

    /** @test */
    public function versi_terbaru_yang_dipakai(): void
    {
        $t = $this->buku();
        $this->berkas($t, 'ebook', 'https://drive.test/v1', 'selesai', 1);
        $this->berkas($t, 'ebook', 'https://drive.test/v2', 'selesai', 2);

        $this->assertSame('https://drive.test/v2', $this->artefak($t)['final_book_file']['value']);
    }

    /** @test */
    public function artikel_mengambil_loa_dan_naskah_final_dari_berkas(): void
    {
        $t = Title::create(['title' => 'Artikel Artefak', 'jenis' => 'artikel',
                            'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->berkas($t, 'loa', 'https://drive.test/loa');
        $this->berkas($t, 'final', 'https://drive.test/final');

        $a = $this->artefak($t);

        $this->assertSame('https://drive.test/loa', $a['loa']['value']);
        $this->assertSame('https://drive.test/final', $a['final_naskah']['value']);
    }

    /**
     * Direktori Jurnal menang atas berkas naskah untuk LoA — ia sumber yang lebih resmi.
     *
     * @test
     */
    public function loa_dari_direktori_menang_atas_berkas_naskah(): void
    {
        $t = Title::create(['title' => 'Artikel LoA', 'jenis' => 'artikel',
                            'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->berkas($t, 'loa', 'https://drive.test/loa-berkas');
        $journal = Journal::create(['nama' => 'Jurnal Uji']);
        JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $t->id,
                                   'loa_url' => 'https://direktori.test/loa']);

        $this->assertSame('https://direktori.test/loa', $this->artefak($t)['loa']['value']);
    }

    /**
     * Nilai yang sudah disimpan manual tak boleh ditimpa prefill.
     *
     * @test
     */
    public function nilai_tersimpan_menang_atas_prefill(): void
    {
        $t = $this->buku();
        $this->berkas($t, 'ebook', 'https://drive.test/otomatis');
        TitleArchiveArtifact::create([
            'title_id' => $t->id, 'key' => 'final_book_file', 'label' => 'File Buku Final (ber-ISBN)',
            'type' => 'file', 'value' => 'https://manual.test/pilihan', 'is_custom' => false,
        ]);

        $this->assertSame('https://manual.test/pilihan', $this->artefak($t)['final_book_file']['value']);
    }

    /**
     * Sumbernya ikut dilaporkan, supaya UI bisa menyebut "dari mana".
     *
     * @test
     */
    public function artefak_dari_modul_lain_menyebut_sumbernya(): void
    {
        $t = $this->buku();
        BookIsbn::create(['title_id' => $t->id, 'status' => 'cetak', 'no_isbn' => '978-111']);

        $a = $this->artefak($t)['isbn'];

        $this->assertTrue($a['dari_luar']);
        $this->assertSame('Direktori ISBN', $a['sumber']);
    }

    /** @test */
    public function artefak_tersimpan_manual_bukan_dari_luar(): void
    {
        $t = $this->buku();
        TitleArchiveArtifact::create([
            'title_id' => $t->id, 'key' => 'scholar_link', 'label' => 'Link Scholar',
            'type' => 'link', 'value' => 'https://scholar.test/x', 'is_custom' => false,
        ]);

        $this->assertFalse($this->artefak($t)['scholar_link']['dari_luar']);
    }

    /**
     * Berkas seluruh slot dibaca dalam SATU query, bukan satu per slot.
     * BookIsbn::berkas() sudah memperingatkan jangan dipanggil di dalam perulangan.
     *
     * @test
     */
    public function berkas_dibaca_dalam_satu_query(): void
    {
        $t = $this->buku();
        $this->berkas($t, 'barcode_isbn', 'https://drive.test/a');
        $this->berkas($t, 'sertifikat_hki', 'https://drive.test/b');
        $this->berkas($t, 'ebook', 'https://drive.test/c');
        $t = $t->fresh();
        $t->load('archiveArtifacts', 'bookIsbn');

        \DB::enableQueryLog();
        app(TitleArchivalService::class)->defaultArtifacts($t);
        $manuscript = collect(\DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'tb_manuscript_files'))
            ->count();
        \DB::disableQueryLog();

        $this->assertSame(1, $manuscript, 'berkas seluruh slot harus dibaca sekali jalan');
    }

    private function user(string $role): \App\Models\User
    {
        $u = \App\Models\User::factory()->create();
        $u->assignRole($role);

        return $u->fresh();
    }

    /** @test */
    public function artefak_terisi_menyebut_sumbernya_di_layar(): void
    {
        $t = $this->buku();
        BookIsbn::create(['title_id' => $t->id, 'status' => 'cetak', 'no_isbn' => '978-111']);

        $this->actingAs($this->user('admin'))->get(route('archive.show', $t->id))
            ->assertOk()
            ->assertSee('978-111')
            ->assertSee('dari Direktori ISBN');
    }

    /** @test */
    public function kartu_menyebut_berapa_artefak_yang_kurang(): void
    {
        $t = $this->buku();
        BookIsbn::create(['title_id' => $t->id, 'status' => 'cetak', 'no_isbn' => '978-222']);

        // Buku punya 6 artefak baku; ISBN mengisi 2 (no_isbn + publish_link kosong? tidak)
        $this->actingAs($this->user('admin'))->get(route('archive.show', $t->id))
            ->assertOk()
            ->assertSee('Masih kurang 5 dari 6');
    }

    /** @test */
    public function artefak_lengkap_tidak_menampilkan_peringatan_kurang(): void
    {
        $t = $this->buku();
        BookIsbn::create(['title_id' => $t->id, 'status' => 'cetak',
                          'no_isbn' => '978-333', 'link_terbit' => 'https://toko.test/b']);
        $this->berkas($t, 'barcode_isbn', 'https://drive.test/barcode');
        $this->berkas($t, 'sertifikat_hki', 'https://drive.test/hki');
        $this->berkas($t, 'ebook', 'https://drive.test/ebook');
        TitleArchiveArtifact::create([
            'title_id' => $t->id, 'key' => 'scholar_link', 'label' => 'Link Scholar',
            'type' => 'link', 'value' => 'https://scholar.test/b', 'is_custom' => false,
        ]);

        $this->actingAs($this->user('admin'))->get(route('archive.show', $t->id))
            ->assertOk()
            ->assertSee('Artefak lengkap')
            ->assertDontSee('Masih kurang');
    }

    /**
     * Artefak bertipe `text` (hanya `isbn`) nilainya BUKAN URL — memasang tautan
     * padanya menghasilkan href rusak.
     *
     * @test
     */
    public function artefak_teks_tidak_dijadikan_tautan(): void
    {
        $t = $this->buku();
        BookIsbn::create(['title_id' => $t->id, 'status' => 'cetak', 'no_isbn' => '978-444']);

        $this->actingAs($this->user('admin'))->get(route('archive.show', $t->id))
            ->assertOk()
            ->assertDontSee('href="978-444"', false);
    }

    /** @return array{0: Title, 1: \App\Models\TitleProgress} */
    private function naskahBerRiwayat(): array
    {
        $t     = $this->buku();
        $pj    = $this->user('admin');
        $pel   = $this->user('production');
        $order = \App\Models\Order::factory()->create();
        $d     = \App\Models\OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'bk_mandiri',
            'title' => $t->title, 'title_id' => $t->id,
        ]);
        $p = \App\Models\TitleProgress::create([
            'order_detail_id' => $d->id, 'status' => 'terbit', 'bidang' => 'buku',
            'started_at' => now(), 'archived_at' => now(),
            'pj_user_id' => $pj->id, 'pelaksana_user_id' => $pel->id,
        ]);
        \App\Models\TitleProgressLog::create([
            'title_progress_id' => $p->id, 'event' => 'status_advanced',
            'from_value' => 'Cetak', 'to_value' => 'Terbit',
            'changed_by' => $pj->id, 'note' => 'Naik ke terbit',
        ]);

        return [$t->fresh(), $p];
    }

    /**
     * PIC tak lagi diketik per artefak: siapa bertanggung jawab sudah tercatat di
     * naskahnya, dan mengetiknya ulang hanya menghasilkan salinan yang bisa berbeda.
     *
     * @test
     */
    public function penanggung_jawab_diturunkan_dari_naskah(): void
    {
        [$t] = $this->naskahBerRiwayat();

        $r = app(TitleArchivalService::class)->riwayatLengkap($t);

        $this->assertCount(1, $r['orang']);
        $this->assertNotSame('—', $r['orang'][0]['pj']);
        $this->assertNotSame('—', $r['orang'][0]['pelaksana']);
        $this->assertSame('Terbit', $r['orang'][0]['tahap']);
    }

    /** @test */
    public function riwayat_naskah_ikut_terkumpul(): void
    {
        [$t] = $this->naskahBerRiwayat();

        $r = app(TitleArchivalService::class)->riwayatLengkap($t);
        $baris = $r['riwayat']->firstWhere('aksi', 'Maju tahap');

        $this->assertNotNull($baris, 'log naskah harus ikut terkumpul');
        $this->assertSame('Cetak', $baris['dari']);
        $this->assertSame('Terbit', $baris['ke']);
        $this->assertSame('Naik ke terbit', $baris['note']);
    }

    /** Order yang ditarik tetap tercatat — arsip adalah laporan sejarah. */
    /** @test */
    public function order_ditarik_tetap_muncul_dengan_penanda(): void
    {
        [$t, $p] = $this->naskahBerRiwayat();
        $p->update(['withdrawn_at' => now()]);

        $r = app(TitleArchivalService::class)->riwayatLengkap($t->fresh());

        $this->assertTrue($r['orang'][0]['ditarik']);
    }

    /** @test */
    public function layar_arsip_menampilkan_penanggung_jawab_dan_riwayat(): void
    {
        [$t] = $this->naskahBerRiwayat();

        $this->actingAs($this->user('admin'))->get(route('archive.show', $t->id))
            ->assertOk()
            ->assertSee('Penanggung Jawab')
            ->assertSee('Riwayat Perubahan')
            ->assertSee('Naik ke terbit');
    }

    /** Dropdown PIC dicabut — tak boleh ada lagi input untuknya. */
    /** @test */
    public function form_artefak_tak_lagi_punya_dropdown_pic(): void
    {
        [$t] = $this->naskahBerRiwayat();

        $this->actingAs($this->user('admin'))->get(route('archive.show', $t->id))
            ->assertOk()
            ->assertDontSee('[pic_user_id]', false)
            ->assertSee('Catatan (opsional)');
    }
}
