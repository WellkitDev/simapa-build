<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\ChapterProgress;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pelaksana bab: penugasan, penugasan ULANG, dan pemisahan dari pelaksana level order.
 *
 * Dua tingkat menulis ke tabel berbeda tanpa sinkronisasi — `tb_title_progress` untuk
 * order, `tb_chapter_progress` untuk bab. Untuk buku kolaborasi yang benar-benar
 * mengerjakan adalah pelaksana BAB, jadi layar tak boleh mengklaim satu nama level order.
 */
class PelaksanaBabTest extends TestCase
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

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    private function produksi(string $nama): User
    {
        $u = User::factory()->create(['name' => $nama]);
        $u->assignRole('production');

        return $u;
    }

    /**
     * Buku kolaborasi: satu order per bab, tiap order punya authornya, tiap bab punya
     * ChapterProgress.
     *
     * @return array{0: Title, 1: TitleProgress}
     */
    private function bukuKolab(int $jumlahBab = 3): array
    {
        $book = Title::create([
            'title' => 'Buku Kolab ' . fake()->unique()->words(2, true),
            'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui',
        ]);

        $pertama = null;
        for ($i = 1; $i <= $jumlahBab; $i++) {
            $bab = $book->chapters()->create(['judul' => "Bab {$i}", 'urutan' => $i]);
            $bab->progress()->create(['status' => 'menunggu', 'started_at' => now()]);

            $order  = Order::factory()->create();
            $detail = OrderDetail::factory()->create([
                'order_id' => $order->id, 'type' => 'bk_kolab',
                'title' => $book->title, 'title_id' => $book->id,
                'chapters' => $i, 'naskah_type' => 'dibuatkan',
            ]);
            $author = Author::create([
                'name' => "Penulis {$i}", 'email' => "p{$i}-" . uniqid() . '@uji.test',
            ]);
            $detail->authors()->attach($author->id, ['position' => 1]);
            $bab->authors()->attach($author->id, ['position' => 1]);

            $p = TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => 'pembuatan',
                'assigned_role' => 'production', 'bidang' => 'buku', 'started_at' => now(),
            ]);
            $pertama ??= $p;
        }

        return [$book->fresh(), $pertama];
    }

    private function halaman(TitleProgress $p): string
    {
        return $this->actingAs($this->superadmin())
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();
    }

    // ─── penugasan ulang ───

    /**
     * Bug: `applyChapterAssignment()` memindahkan bab `menunggu → pembuatan` saat
     * pelaksana dipasang, sementara formulirnya hanya dirender saat status masih
     * `menunggu`. Begitu terpasang, formulirnya lenyap dan pelaksana bab tak bisa
     * diubah lagi — padahal servicenya mengizinkan.
     *
     * @test
     */
    public function pelaksana_bab_yang_sudah_terpasang_masih_bisa_diganti_lewat_layar(): void
    {
        [$book, $p] = $this->bukuKolab(2);
        $budi  = $this->produksi('Budi');
        $citra = $this->produksi('Citra');

        $cp = ChapterProgress::first();
        $cp->update(['pelaksana_user_id' => $budi->id, 'status' => 'pembuatan']);

        $isi = $this->halaman($p);

        $this->assertStringContainsString('gantiPelaksana' . $cp->id, $isi,
            'Harus ada jalan mengubah pelaksana bab yang sudah terpasang.');
        $this->assertStringContainsString('Ganti', $isi);
    }

    /** @test */
    public function mengganti_pelaksana_bab_benar_benar_tersimpan(): void
    {
        [$book, $p] = $this->bukuKolab(2);
        $budi  = $this->produksi('Budi');
        $citra = $this->produksi('Citra');

        $cp = ChapterProgress::first();
        $cp->update(['pelaksana_user_id' => $budi->id, 'status' => 'pembuatan']);

        $this->actingAs($this->superadmin())
            ->post(route('naskah.bab.distribusi', $cp->id), ['pelaksana_user_id' => $citra->id])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame($citra->id, $cp->fresh()->pelaksana_user_id);
    }

    /** @test */
    public function bab_selesai_tak_lagi_menawarkan_penggantian(): void
    {
        [$book, $p] = $this->bukuKolab(2);
        $cp = ChapterProgress::first();
        $cp->update(['pelaksana_user_id' => $this->produksi('Budi')->id, 'status' => 'selesai']);

        $this->assertStringNotContainsString('gantiPelaksana' . $cp->id, $this->halaman($p),
            'Bab yang sudah selesai tak perlu ditugaskan ulang.');
    }

    // ─── dua tingkat tak boleh saling membantah ───

    /**
     * Kartu Informasi membaca `tb_title_progress.pelaksana_user_id`, tabel bab membaca
     * `tb_chapter_progress.pelaksana_user_id`. Untuk buku kolaborasi keduanya bisa
     * berbeda, dan menampilkan satu nama level order berarti mengklaim sesuatu yang
     * dibantah tabel tepat di bawahnya.
     *
     * @test
     */
    public function buku_kolaborasi_tidak_mengklaim_satu_pelaksana_di_kartu_informasi(): void
    {
        [$book, $p] = $this->bukuKolab(3);

        $andi = $this->produksi('Andi');
        $budi = $this->produksi('Budi');

        // Level ORDER: Andi. Level BAB: Budi. Persis keadaan yang membingungkan.
        TitleProgress::query()->update(['pelaksana_user_id' => $andi->id]);
        ChapterProgress::query()->update(['pelaksana_user_id' => $budi->id, 'status' => 'pembuatan']);

        $isi = $this->halaman($p->fresh());

        $this->assertStringContainsString('Per bab', $isi);
        $this->assertStringContainsString('lihat tabel bab', $isi);
    }

    /** @test */
    public function selektor_pelaksana_level_order_disembunyikan_untuk_buku_kolaborasi(): void
    {
        [$book, $p] = $this->bukuKolab(2);

        $this->assertStringNotContainsString('naskah/' . $p->order_detail_id . '/distribusi',
            $this->halaman($p),
            'Untuk buku kolaborasi, penugasan yang benar ada di tabel bab.');
    }

    /**
     * REGRESI YANG PERNAH TERJADI: Pelaksana dan Penanggung jawab dulu berbagi satu
     * `@if ($izin['assign'])`, jadi menyembunyikan Pelaksana untuk buku kolaborasi ikut
     * menyembunyikan PJ — dan buku kolaborasi kehilangan satu-satunya cara menetapkan
     * penanggung jawabnya.
     *
     * PJ berlaku untuk SETIAP jenis naskah: ia yang menerima notifikasi tahap, ditagih
     * saat lewat SLA, dan namanya tercetak di laporan arsip.
     *
     * @test
     */
    public function buku_kolaborasi_TETAP_punya_selektor_penanggung_jawab(): void
    {
        [$book, $p] = $this->bukuKolab(2);
        $isi = $this->halaman($p);

        $this->assertStringContainsString('/oper-pj', $isi,
            'Menyembunyikan Pelaksana tak boleh ikut menyembunyikan PJ.');
        $this->assertStringContainsString('name="pj_user_id"', $isi);
    }

    /** @test */
    public function naskah_tanpa_pj_ditandai_wajib_diisi(): void
    {
        [$book, $p] = $this->bukuKolab(2);
        $isi = $this->halaman($p);

        $this->assertStringContainsString('wajib diisi', $isi);
        $this->assertStringContainsString('Belum ditetapkan', $isi);
        $this->assertStringContainsString('Tetapkan', $isi,
            'Tombolnya berbunyi "Tetapkan" selama PJ masih kosong, bukan "Oper".');
    }

    /** @test */
    public function naskah_yang_sudah_ber_pj_tak_lagi_ditandai(): void
    {
        [$book, $p] = $this->bukuKolab(2);

        $admin = User::factory()->create(['name' => 'Fitri']);
        $admin->assignRole('admin');
        TitleProgress::query()->update(['pj_user_id' => $admin->id]);

        $isi = $this->halaman($p->fresh());

        $this->assertStringContainsString('Fitri', $isi);
        $this->assertStringNotContainsString('wajib diisi', $isi);
    }

    /** @test */
    public function artikel_tetap_punya_selektor_pelaksana_level_order(): void
    {
        $t = Title::create([
            'title' => 'Artikel Biasa', 'jenis' => 'artikel',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
        ]);
        $detail = OrderDetail::factory()->create([
            'type' => 'at_mandiri', 'title' => $t->title, 'title_id' => $t->id,
        ]);
        $p = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'pembuatan',
            'assigned_role' => 'production', 'started_at' => now(),
        ]);

        $this->assertStringContainsString('/distribusi', $this->halaman($p),
            'Artikel dan buku mandiri tak dipecah per bab — selektornya harus tetap ada.');
    }

    // ─── kolom tabel ───

    /**
     * CHAPTER_STAGES = menunggu → pembuatan → editing → selesai. Tombol maju di bab
     * karena itu sering BUKAN menyelesaikan apa pun — ia memajukan satu langkah.
     * Labelnya harus menyebut tahap tujuannya.
     *
     * Terlihat setelah formulir penugasan pindah keluar dari kolom Aksi: bab `menunggu`
     * yang dulu tertangkap cabang penugasan kini jatuh ke cabang maju, dan labelnya
     * yang dipatok "Selesaikan Bab" jadi berbohong.
     *
     * @test
     */
    public function tombol_maju_bab_menyebut_tahap_tujuannya(): void
    {
        [$book, $p] = $this->bukuKolab(2);
        $cp = ChapterProgress::first();
        $cp->update(['status' => 'menunggu', 'pelaksana_user_id' => $this->produksi('Budi')->id]);

        $isi = $this->halaman($p);

        $this->assertStringContainsString('Majukan ke Pembuatan', $isi,
            'Dari menunggu, tombolnya memajukan ke Pembuatan — bukan menyelesaikan bab.');
        $this->assertStringNotContainsString('✓ Selesaikan Bab', $isi);
    }

    /** @test */
    public function tombol_selesaikan_muncul_hanya_saat_tahap_berikutnya_memang_selesai(): void
    {
        [$book, $p] = $this->bukuKolab(2);
        $cp = ChapterProgress::first();
        $cp->update(['status' => 'editing', 'pelaksana_user_id' => $this->produksi('Budi')->id]);

        $this->assertStringContainsString('✓ Selesaikan Bab', $this->halaman($p));
    }

    // ─── aksi bab mandiri: satu aksi utama per keadaan ───

    /** @return array{0: Title, 1: TitleProgress, 2: \App\Models\TitleChapter} */
    private function bukuMandiri(): array
    {
        $book = Title::create([
            'title' => 'Buku Mandiri ' . fake()->unique()->words(2, true),
            'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui',
        ]);

        $bab = $book->chapters()->create(['judul' => 'Bab 1', 'urutan' => 1]);
        $bab->progress()->create(['status' => 'menunggu', 'started_at' => now()]);

        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'bk_kolab',
            'title' => $book->title, 'title_id' => $book->id,
            'chapters' => 1, 'naskah_type' => 'mandiri',
        ]);
        $author = Author::create(['name' => 'Penulis Sendiri', 'email' => uniqid() . '@uji.test']);
        $detail->authors()->attach($author->id, ['position' => 1]);
        $bab->authors()->attach($author->id, ['position' => 1]);

        $p = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'pembuatan',
            'assigned_role' => 'production', 'bidang' => 'buku', 'started_at' => now(),
        ]);

        return [$book->fresh(), $p, $bab];
    }

    /**
     * Selama naskahnya belum masuk, satu-satunya aksi yang berarti adalah mengunggah —
     * memajukan bab tanpa naskah tak berarti apa-apa. Sebelumnya kedua formulir selalu
     * bertumpuk di sel selebar 290px.
     *
     * @test
     */
    public function bab_mandiri_tanpa_naskah_hanya_menawarkan_unggah(): void
    {
        [$book, $p, $bab] = $this->bukuMandiri();
        $isi = $this->halaman($p);

        $this->assertStringContainsString('⬆ Naskah', $isi);
        $this->assertStringContainsString('Naskah dikirim author sendiri', $isi);
        $this->assertStringNotContainsString('Majukan ke', $isi,
            'Tak ada gunanya memajukan bab yang naskahnya belum ada.');
    }

    /** @test */
    public function bab_mandiri_bernaskah_menawarkan_maju_dan_ganti_naskah_sebagai_tautan(): void
    {
        [$book, $p, $bab] = $this->bukuMandiri();

        \App\Models\ManuscriptFile::create([
            'title_id' => $book->id, 'title_chapter_id' => $bab->id,
            'slot' => 'masuk', 'status' => 'selesai', 'version' => 1,
            'original_name' => 'naskah-author.docx', 'drive_url' => 'https://drive/x',
        ]);

        $isi = $this->halaman($p);

        $this->assertStringContainsString('Majukan ke Editing', $isi,
            'Naskah sudah masuk — giliran tombol maju yang jadi utama.');
        $this->assertStringContainsString('ganti naskah', $isi,
            'Mengganti naskah turun jadi tautan, bukan formulir yang selalu terbuka.');
    }

    /**
     * Berkas yang GAGAL diunggah tak boleh dihitung sebagai "naskah sudah masuk" —
     * berkasnya memang tak pernah sampai.
     *
     * @test
     */
    public function berkas_gagal_tidak_dihitung_sebagai_naskah_masuk(): void
    {
        [$book, $p, $bab] = $this->bukuMandiri();

        \App\Models\ManuscriptFile::create([
            'title_id' => $book->id, 'title_chapter_id' => $bab->id,
            'slot' => 'masuk', 'status' => 'gagal', 'version' => 1,
            'original_name' => 'gagal.docx',
        ]);

        $this->assertStringContainsString('⬆ Naskah', $this->halaman($p),
            'Unggahan yang gagal harus tetap menawarkan unggah ulang.');
    }

    /** @test */
    public function judul_kolom_tabel_bab_dipadatkan(): void
    {
        [$book, $p] = $this->bukuKolab(2);
        $isi = $this->halaman($p);

        $this->assertStringContainsString('<th>Bab</th>', $isi);
        $this->assertStringContainsString('<th>Author</th>', $isi);
        $this->assertStringNotContainsString('Judul Bab', $isi);
        $this->assertStringNotContainsString('Author (naskah dari siapa)', $isi);
    }
}
