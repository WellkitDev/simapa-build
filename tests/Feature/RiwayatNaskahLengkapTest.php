<?php

namespace Tests\Feature;

use App\Models\ManuscriptRevision;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\ManuscriptFileService;
use App\Services\ManuscriptRevisionService;
use App\Services\RiwayatNaskahService;
use App\Services\TitleProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Riwayat naskah harus menampung aktivitas SEMUA pengguna.
 *
 * Kartunya berjanji "semua aksi tercatat", tapi sampai 2026-08-23 unggahan berkas tak
 * dicatat sama sekali — ia hanya muncul bila kebetulan memicu maju tahap. Unggah hasil
 * editing, LoA, cover, berkas ISBN, dan berkas revisi tak meninggalkan jejak siapa pun.
 */
class RiwayatNaskahLengkapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // uploadFile WAJIB mengembalikan nilai: antrean berjalan sinkron di tes, jadi
        // UnggahBerkasKeDrive langsung dieksekusi dan melempar bila hasilnya null.
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'd1', 'url' => 'https://drive/1']);
            $m->shouldReceive('getOrCreateFolderByPath')->andReturn('folder-1');
        })->shouldIgnoreMissing();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role, string $nama): User
    {
        $u = User::factory()->create(['name' => $nama]);
        $u->assignRole($role);

        return $u;
    }

    /** @return array{0: Title, 1: TitleProgress} */
    private function naskah(int $order = 1, string $status = 'editing'): array
    {
        $title = Title::create([
            'title' => 'Naskah Riwayat ' . fake()->unique()->words(2, true),
            'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
            'link_terbit' => 'https://uji.test/' . fake()->unique()->slug(),
        ]);

        $pertama = null;
        for ($i = 0; $i < $order; $i++) {
            $detail = OrderDetail::factory()->create([
                'order_id' => Order::factory()->create()->id,
                'type' => 'at_mandiri', 'title' => $title->title, 'title_id' => $title->id,
            ]);
            $p = TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => $status,
                'assigned_role' => TitleProgress::getHandlerForStatus($status),
                'started_at' => now(),
            ]);
            $pertama ??= $p;
        }

        return [$title->fresh(), $pertama];
    }

    private function peristiwa(TitleProgress $p): array
    {
        return app(RiwayatNaskahService::class)->untukLayar($p)->pluck('event')->all();
    }

    // ─── unggahan ───

    /** @test */
    public function unggah_berkas_tercatat_beserta_pengunggahnya(): void
    {
        [$title, $p] = $this->naskah();
        $budi = $this->user('production', 'Budi');

        app(ManuscriptFileService::class)->upload(
            $title, null, 'hasil_editing',
            UploadedFile::fake()->create('hasil-edit.docx', 20),
            $budi
        );

        $log = app(RiwayatNaskahService::class)->untukLayar($p)
            ->firstWhere('event', 'berkas_diunggah');

        $this->assertNotNull($log, 'Unggah hasil editing wajib tercatat — dulu tak sama sekali.');
        $this->assertSame($budi->id, $log->changed_by);
        $this->assertSame('Hasil Editing', $log->to_value);
        $this->assertStringContainsString('hasil-edit.docx', $log->note);
        $this->assertStringContainsString('v1', $log->note);
    }

    /**
     * Slot yang TIDAK memajukan tahap dulu benar-benar tak berjejak. Ini yang paling
     * sering hilang: LoA, cover, berkas ISBN.
     *
     * @test
     */
    public function slot_yang_tak_memajukan_tahap_tetap_tercatat(): void
    {
        [$title, $p] = $this->naskah();
        $rina = $this->user('admin', 'Rina');

        foreach (['loa', 'final'] as $slot) {
            app(ManuscriptFileService::class)->upload(
                $title, null, $slot, UploadedFile::fake()->create("{$slot}.pdf", 10), $rina
            );
        }

        $slotTercatat = app(RiwayatNaskahService::class)->untukLayar($p)
            ->where('event', 'berkas_diunggah')->pluck('to_value')->all();

        $this->assertContains('LoA (Letter of Acceptance)', $slotTercatat);
        $this->assertContains('Naskah Final', $slotTercatat);
    }

    // ─── putaran revisi ───

    /** @test */
    public function putaran_revisi_tercatat_dari_dibuka_sampai_ditutup(): void
    {
        [$title, $p] = $this->naskah(status: 'revisi');
        $rina  = $this->user('admin', 'Rina');
        $budi  = $this->user('production', 'Budi');

        $putaran = app(ManuscriptRevisionService::class)->buka(
            $title, 'revisi', 'submit', $rina, 'Metodologi bab 3 diperjelas', $budi
        );

        app(ManuscriptRevisionService::class)->jawab(
            $putaran, $budi, [UploadedFile::fake()->create('rev2.docx', 20)]
        );

        app(ManuscriptRevisionService::class)->tutup(
            $putaran->fresh(), $rina, 'Reviewer menerima perbaikannya'
        );

        $peristiwa = $this->peristiwa($p);

        $this->assertContains('revisi_diminta', $peristiwa);
        $this->assertContains('revisi_dijawab', $peristiwa);
        $this->assertContains('revisi_ditutup', $peristiwa);
    }

    /** @test */
    public function permintaan_revisi_menyebut_siapa_yang_dituju(): void
    {
        [$title, $p] = $this->naskah(status: 'revisi');
        $rina = $this->user('admin', 'Rina');
        $budi = $this->user('production', 'Budi');

        app(ManuscriptRevisionService::class)->buka(
            $title, 'revisi', 'submit', $rina, 'Perbaiki sitasi', $budi
        );

        $log = app(RiwayatNaskahService::class)->untukLayar($p)
            ->firstWhere('event', 'revisi_diminta');

        $this->assertSame($rina->id, $log->changed_by);
        $this->assertStringContainsString('Budi', $log->note);
        $this->assertStringContainsString('Perbaiki sitasi', $log->note);
    }

    // ─── penggabungan se-grup ───

    /**
     * Berkas dan putaran milik JUDUL, dicatat sekali saja. Tanpa penggabungan, membuka
     * order kedua sebuah judul menyembunyikan separuh sejarahnya.
     *
     * @test
     */
    public function riwayat_sama_dilihat_dari_order_mana_pun(): void
    {
        [$title, $pertama] = $this->naskah(order: 3);
        $budi = $this->user('production', 'Budi');

        app(ManuscriptFileService::class)->upload(
            $title, null, 'masuk', UploadedFile::fake()->create('naskah.docx', 20), $budi
        );

        $semua = TitleProgress::whereHas('orderDetail',
            fn ($q) => $q->where('title_id', $title->id))->get();

        $this->assertCount(3, $semua);

        foreach ($semua as $p) {
            $this->assertContains('berkas_diunggah', $this->peristiwa($p),
                "Order {$p->id} harus melihat unggahan yang sama.");
        }
    }

    /**
     * Perpindahan tahap dicatat pada SETIAP anggota grup (applyGroup memanggil
     * applyStatus per order), jadi menggabungkan begitu saja menghasilkan tiga baris
     * "Maju tahap" yang identik.
     *
     * @test
     */
    public function perpindahan_tahap_tidak_muncul_berkali_kali(): void
    {
        [$title, $pertama] = $this->naskah(order: 3, status: 'editing');
        $sa = $this->user('superadmin', 'Super');

        app(TitleProgressService::class)->advance($pertama, $sa);

        $maju = app(RiwayatNaskahService::class)->untukLayar($pertama->fresh())
            ->where('event', 'status_advanced');

        $this->assertCount(1, $maju,
            'Tiga order, satu perpindahan — riwayat harus menyebutnya sekali.');
    }

    // ─── tampilan ───

    /** @test */
    public function layar_naskah_menampilkan_pengunggah_dan_berkasnya(): void
    {
        [$title, $p] = $this->naskah();
        $budi = $this->user('production', 'Budi');

        app(ManuscriptFileService::class)->upload(
            $title, null, 'masuk', UploadedFile::fake()->create('naskah-budi.docx', 20), $budi
        );

        $isi = $this->actingAs($this->user('superadmin', 'Super'))
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Unggah berkas', $isi);
        $this->assertStringContainsString('Budi', $isi);
        $this->assertStringContainsString('naskah-budi.docx', $isi);
    }

    /**
     * Kegagalan unggah ikut masuk riwayat. Notifikasi terbaca sekali lalu hilang;
     * riwayat yang menjawab "kenapa berkas ini tak pernah ada" berbulan kemudian.
     *
     * @test
     */
    public function unggahan_yang_gagal_ikut_tercatat(): void
    {
        [$title, $p] = $this->naskah();
        $budi = $this->user('production', 'Budi');

        $berkas = app(ManuscriptFileService::class)->upload(
            $title, null, 'masuk', UploadedFile::fake()->create('gagal.docx', 20), $budi
        );

        (new \App\Jobs\UnggahBerkasKeDrive($berkas->id))
            ->failed(new \RuntimeException('Drive menolak unggahan'));

        $log = app(RiwayatNaskahService::class)->untukLayar($p)
            ->firstWhere('event', 'berkas_gagal');

        $this->assertNotNull($log);
        $this->assertSame($budi->id, $log->changed_by, 'Pelakunya pengunggahnya, bukan sistem.');
        $this->assertStringContainsString('Drive menolak', $log->note);
    }

    /** @test */
    public function mencatat_riwayat_tak_pernah_menggagalkan_unggahan(): void
    {
        // Judul tanpa progress sama sekali — tak ada tempat menempelkan riwayatnya.
        $title = Title::create([
            'title' => 'Judul Tanpa Order', 'jenis' => 'artikel',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
        ]);
        $budi = $this->user('production', 'Budi');

        $berkas = app(ManuscriptFileService::class)->upload(
            $title, null, 'masuk', UploadedFile::fake()->create('x.docx', 10), $budi
        );

        $this->assertNotNull($berkas->id, 'Unggahan tetap berhasil meski riwayatnya tak bisa dicatat.');
        $this->assertNull(
            app(RiwayatNaskahService::class)->catatJudul($title, 'berkas_diunggah', $budi)
        );
    }
}
