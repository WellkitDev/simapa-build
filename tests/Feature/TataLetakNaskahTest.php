<?php

namespace Tests\Feature;

use App\Models\ManuscriptRevision;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tata letak layar naskah dan blok kiri yang bisa diatur.
 *
 * Yang dijaga bukan rupa, melainkan URUTAN dan CAKUPAN: kolom kanan punya urutan yang
 * bermakna dan sengaja tak bisa disusun ulang, sementara kolom kiri harus punya kepala
 * seragam untuk tiap bloknya. Berlaku sama untuk buku maupun artikel.
 */
class TataLetakNaskahTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'd1', 'url' => 'https://drive/1']);
        });
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** @return array{0: Title, 1: TitleProgress} */
    private function naskah(string $jenis = 'artikel', string $status = 'editing'): array
    {
        $title = Title::create([
            'title' => 'Naskah ' . fake()->unique()->words(3, true),
            'jenis' => $jenis, 'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
            'link_terbit' => 'https://uji.test/' . fake()->unique()->slug(),
        ]);
        $detail = OrderDetail::factory()->create([
            'type'  => $jenis === 'buku' ? 'bk_mandiri' : 'at_mandiri',
            'title' => $title->title, 'title_id' => $title->id,
        ]);
        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'assigned_role' => TitleProgress::getHandlerForStatus($status),
            'started_at' => now(),
        ]);

        return [$title, $progress];
    }

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    private function halaman(TitleProgress $p): string
    {
        return $this->actingAs($this->superadmin())
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();
    }

    /** Urutan kemunculan penanda di HTML. */
    private function urutan(string $isi, array $penanda): array
    {
        $pos = [];
        foreach ($penanda as $nama => $cari) {
            $p = strpos($isi, $cari);
            $this->assertNotFalse($p, "Penanda '{$nama}' tak ditemukan di halaman.");
            $pos[$nama] = $p;
        }
        asort($pos);

        return array_keys($pos);
    }

    // ─── susunan kolom ───

    /** @test */
    public function kolom_kiri_berurut_informasi_jurnal_brief_publikasi(): void
    {
        [, $p] = $this->naskah('artikel');

        $this->assertSame(
            ['informasi', 'jurnal', 'brief', 'publikasi'],
            $this->urutan($this->halaman($p), [
                'informasi' => 'data-blok="informasi"',
                'jurnal'    => 'data-blok="jurnal"',
                'brief'     => 'data-blok="brief"',
                'publikasi' => 'data-blok="info-publikasi"',
            ])
        );
    }

    /**
     * Urutan kolom kanan BERMAKNA: blok opsional yang menahan laju harus terbaca
     * sebelum tombol Aksi yang tertahan. Itu sebabnya kolom ini sengaja tak bisa
     * disusun ulang — lihat tes berikutnya.
     *
     * @test
     */
    public function kolom_kanan_berurut_opsional_aksi_berkas_riwayat(): void
    {
        [$title, $p] = $this->naskah('artikel', 'revisi');
        ManuscriptRevision::create([
            'title_id' => $title->id, 'round' => 1, 'stage' => 'revisi',
            'from_stage' => 'submit', 'request_note' => 'Perlu diperbaiki',
        ]);

        $this->assertSame(
            ['putaran', 'aksi', 'berkas', 'riwayat'],
            $this->urutan($this->halaman($p), [
                'putaran' => 'Putaran Perbaikan',
                'aksi'    => '>Aksi<',
                'berkas'  => 'File Naskah',
                'riwayat' => 'Riwayat (semua aksi tercatat)',
            ])
        );
    }

    /** @test */
    public function berkas_naskah_pindah_ke_kolom_kanan(): void
    {
        [, $p] = $this->naskah('artikel');
        $isi   = $this->halaman($p);

        $posWadahKiri = strpos($isi, 'id="blokAturKiri"');
        $posTutupKiri = strpos($isi, 'col-lg-7');
        $posBerkas    = strpos($isi, 'File Naskah');

        $this->assertNotFalse($posBerkas);
        $this->assertGreaterThan($posTutupKiri, $posBerkas,
            'Berkas Naskah harus berada di kolom kanan, bukan di dalam blok kiri yang bisa disusun ulang.');
        $this->assertLessThan($posBerkas, $posWadahKiri);
    }

    // ─── perilaku blok ───

    /** @test */
    public function tiap_blok_kiri_punya_pegangan_pin_dan_lipat(): void
    {
        [, $p] = $this->naskah('artikel');
        $isi   = $this->halaman($p);

        $this->assertSame(4, substr_count($isi, 'class="blok-atur-pegangan text-muted"'),
            'Empat blok kiri, empat pegangan geser.');
        $this->assertSame(4, substr_count($isi, 'blok-atur-pin"'));
        $this->assertSame(4, substr_count($isi, 'blok-atur-lipat"'));
        $this->assertStringContainsString('Kembalikan susunan bawaan', $isi,
            'Harus ada jalan pulang: orang yang melipat semuanya perlu bisa kembali.');
    }

    /** @test */
    public function pustaka_penyusun_dimuat_dari_berkas_lokal(): void
    {
        [, $p] = $this->naskah('artikel');

        $this->assertStringContainsString('assets/plugins/sortablejs/Sortable.min.js', $this->halaman($p),
            'Sortable harus dari repo, bukan CDN — CSP dan mode luring bergantung padanya.');
    }

    /**
     * Kolom kanan tak boleh ikut bisa disusun ulang. Wadah penyusunnya hanya satu, dan
     * ia hanya membungkus blok kiri.
     *
     * @test
     */
    public function hanya_kolom_kiri_yang_bisa_disusun_ulang(): void
    {
        [, $p] = $this->naskah('artikel');
        $isi   = $this->halaman($p);

        $this->assertSame(1, substr_count($isi, 'id="blokAturKiri"'));

        // Dicari markup ELEMENnya, bukan sekadar teks `data-blok=`: skrip penyusun
        // menyebut atribut yang sama di dalam selektornya, dan penanda longgar akan
        // menemukan skrip itu lalu gagal karena sebab yang salah.
        $posWadah  = strpos($isi, 'id="blokAturKiri"');
        $posKanan  = strpos($isi, 'col-lg-7');
        $posAkhir  = strrpos($isi, 'class="card mb-3 blok-atur" data-blok=');

        $this->assertLessThan($posKanan, $posAkhir,
            'Tak boleh ada blok yang bisa disusun ulang di kolom kanan.');
        $this->assertLessThan($posAkhir, $posWadah);
    }

    // ─── berlaku untuk buku maupun artikel ───

    /** @test */
    public function buku_mendapat_blok_yang_sama_kecuali_jurnal(): void
    {
        [, $p] = $this->naskah('buku');
        $isi   = $this->halaman($p);

        foreach (['informasi', 'brief', 'info-publikasi'] as $blok) {
            $this->assertStringContainsString('data-blok="' . $blok . '"', $isi,
                "Buku juga harus mendapat blok {$blok}.");
        }

        $this->assertStringNotContainsString('data-blok="jurnal"', $isi,
            'Buku tak punya submission jurnal — bloknya tak boleh muncul kosong.');
    }

    /**
     * Susunan tersimpan bisa menyebut blok yang tak ada di naskah ini, dan sebaliknya.
     * Karena itu pemulihan preferensi harus menangani keduanya — dijaga di sisi skrip,
     * dan di sini dikunci bahwa naskah buku memang punya jumlah blok yang berbeda.
     *
     * @test
     */
    public function jumlah_blok_berbeda_antara_buku_dan_artikel(): void
    {
        [, $artikel] = $this->naskah('artikel');
        [, $buku]    = $this->naskah('buku');

        $this->assertSame(4, substr_count($this->halaman($artikel), 'class="card mb-3 blok-atur"'));
        $this->assertSame(3, substr_count($this->halaman($buku), 'class="card mb-3 blok-atur"'));
    }
}
