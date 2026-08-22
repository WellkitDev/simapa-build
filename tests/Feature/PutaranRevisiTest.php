<?php

namespace Tests\Feature;

use App\Models\ManuscriptFile;
use App\Models\ManuscriptRevision;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\ManuscriptRevisionService;
use App\Services\TitleProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Putaran perbaikan: permintaan dari PJ, jawaban dari Pelaksana, dan gerbang yang
 * menahan naskah selama permintaan belum dijawab.
 */
class PutaranRevisiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mock WAJIB lewat container, dan uploadFile WAJIB mengembalikan nilai:
        // UnggahBerkasKeDrive melempar bila hasilnya null, jadi mock telanjang membuat
        // setiap unggahan berakhir 500 — gejala yang menyesatkan ke arah yang salah.
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'drive-1', 'url' => 'https://drive/1']);
        });
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function judul(string $jenis = 'artikel'): Title
    {
        return Title::create([
            'title'       => 'Judul ' . fake()->unique()->words(3, true),
            'jenis'       => $jenis,
            'tipe_naskah' => 'mandiri',
            'status'      => 'disetujui',
            'link_terbit' => 'https://uji.test/' . fake()->unique()->slug(),
        ]);
    }

    private function putaran(Title $t, array $atribut = []): ManuscriptRevision
    {
        return ManuscriptRevision::create(array_merge([
            'title_id'     => $t->id,
            'round'        => ManuscriptRevision::nomorBerikutnya($t->id),
            'stage'        => 'revisi',
            'from_stage'   => 'submit',
            'requested_by' => User::factory()->create()->id,
            'assigned_to'  => User::factory()->create()->id,
            'request_note' => 'Metodologi bab 3 diminta diperjelas',
        ], $atribut));
    }

    private function berkas(Title $t, ManuscriptRevision $p, string $slot, string $status = 'selesai'): ManuscriptFile
    {
        return ManuscriptFile::create([
            'title_id'               => $t->id,
            'manuscript_revision_id' => $p->id,
            'slot'                   => $slot,
            'status'                 => $status,
            'version'                => 1,
            'original_name'          => $slot . '.docx',
        ]);
    }

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    /** @return array{0: Title, 1: TitleProgress} */
    private function naskahBerjudul(string $status, string $jenis = 'artikel'): array
    {
        $judul  = $this->judul($jenis);
        $detail = OrderDetail::factory()->create([
            'type'     => $jenis === 'buku' ? 'bk_mandiri' : 'at_mandiri',
            'title'    => $judul->title,
            'title_id' => $judul->id,
        ]);

        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => $status,
            'assigned_role'   => TitleProgress::getHandlerForStatus($status),
            'started_at'      => now(),
        ]);

        return [$judul, $progress];
    }

    // ─── model ───

    /** @test */
    public function putaran_terbuka_sampai_ditutup(): void
    {
        $p = $this->putaran($this->judul());

        $this->assertTrue($p->terbuka());
        $this->assertFalse($p->terjawab(), 'Belum ada berkas hasil.');

        $p->update(['closed_at' => now()]);

        $this->assertFalse($p->fresh()->terbuka());
    }

    /** @test */
    public function putaran_terjawab_oleh_berkas_selesai_maupun_antre(): void
    {
        foreach (['selesai', 'antre'] as $status) {
            $judul = $this->judul();
            $p     = $this->putaran($judul);
            $this->berkas($judul, $p, 'revisi_hasil', $status);

            $this->assertTrue($p->fresh()->terjawab(),
                "Berkas berstatus {$status} harus dihitung sebagai jawaban.");
        }
    }

    /** @test */
    public function berkas_gagal_tidak_dihitung_sebagai_jawaban(): void
    {
        $judul = $this->judul();
        $p     = $this->putaran($judul);
        $this->berkas($judul, $p, 'revisi_hasil', 'gagal');

        $this->assertFalse($p->fresh()->terjawab());
    }

    /** @test */
    public function berkas_permintaan_bukan_jawaban(): void
    {
        $judul = $this->judul();
        $p     = $this->putaran($judul);
        $this->berkas($judul, $p, 'revisi_minta');

        $this->assertFalse($p->fresh()->terjawab(),
            'Permintaan bukan jawaban atas dirinya sendiri.');
    }

    /** @test */
    public function nomor_putaran_naik_per_judul(): void
    {
        $judul = $this->judul();
        $lain  = $this->judul();

        $this->putaran($judul);
        $this->putaran($judul);
        $this->putaran($lain);

        $this->assertSame(3, ManuscriptRevision::nomorBerikutnya($judul->id));
        $this->assertSame(2, ManuscriptRevision::nomorBerikutnya($lain->id),
            'Nomor putaran per judul, bukan global.');
    }

    // ─── gerbang laju ───

    /** @test */
    public function putaran_dengan_permintaan_belum_dijawab_menahan_laju(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $p = $this->putaran($judul);
        $this->berkas($judul, $p, 'revisi_minta');

        try {
            app(TitleProgressService::class)->advance($progress, $this->superadmin());
            $this->fail('Naskah dengan putaran belum terjawab mestinya tertahan.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ke-1', $e->getMessage(),
                'Pesannya harus menyebut nomor putarannya, bukan sekadar "tidak bisa maju".');
        }

        $this->assertSame('revisi', $progress->fresh()->status);
    }

    /** @test */
    public function putaran_terjawab_tidak_menahan_laju(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $p = $this->putaran($judul);
        $this->berkas($judul, $p, 'revisi_minta');
        $this->berkas($judul, $p, 'revisi_hasil');

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $this->assertSame('loa', $progress->fresh()->status);
    }

    /** @test */
    public function menutup_putaran_membebaskan_naskah_untuk_maju(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $p = $this->putaran($judul);
        $this->berkas($judul, $p, 'revisi_minta');

        app(ManuscriptRevisionService::class)
            ->tutup($p, $this->superadmin(), 'Reviewer menarik permintaannya');

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $this->assertSame('loa', $progress->fresh()->status);
        $this->assertSame('Reviewer menarik permintaannya', $p->fresh()->close_note);
    }

    /** @test */
    public function menutup_putaran_wajib_beralasan(): void
    {
        $p = $this->putaran($this->judul());

        $this->expectException(ValidationException::class);

        app(ManuscriptRevisionService::class)->tutup($p, $this->superadmin(), '  ');
    }

    /** @test */
    public function putaran_pembuatan_tidak_menahan_laju(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('pembuatan');
        $p = $this->putaran($judul, ['stage' => 'pembuatan', 'from_stage' => 'editing']);
        $this->berkas($judul, $p, 'revisi_minta');

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $this->assertSame('editing', $progress->fresh()->status,
            'Pengembalian ke Pembuatan dijawab dengan naskahnya, bukan berkas balasan.');
    }

    /** @test */
    public function maju_wajar_menutup_putaran_tanpa_catatan(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $p = $this->putaran($judul);
        $this->berkas($judul, $p, 'revisi_hasil');

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $segar = $p->fresh();
        $this->assertNotNull($segar->closed_at);
        $this->assertNull($segar->close_note,
            'Catatan kosong itulah yang membedakan penutupan wajar dari pintu darurat.');
    }

    /** @test */
    public function naskah_tanpa_putaran_lewat_revisi_tanpa_hambatan(): void
    {
        [, $progress] = $this->naskahBerjudul('revisi');

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $this->assertSame('loa', $progress->fresh()->status,
            'Tanpa permintaan revisi, tahapnya cukup dilewati.');
    }

    // ─── route & izin ───

    /** @test */
    public function pj_membuka_putaran_lewat_http_dan_pelaksana_dikabari(): void
    {
        [, $progress] = $this->naskahBerjudul('revisi');

        $pelaksana = User::factory()->create();
        $pelaksana->assignRole('production');
        $progress->update(['pelaksana_user_id' => $pelaksana->id]);

        $this->actingAs($this->superadmin())
            ->post(route('naskah.revisi.minta', $progress->order_detail_id), [
                'request_note' => 'Metodologi bab 3 diminta diperjelas',
                'berkas'       => [UploadedFile::fake()->create('reviewer.pdf', 20)],
            ])->assertRedirect()->assertSessionHas('success');

        $putaran = ManuscriptRevision::first();
        $this->assertNotNull($putaran);
        $this->assertSame($pelaksana->id, $putaran->assigned_to,
            'Permintaan otomatis ditujukan ke pelaksana naskah.');
        $this->assertSame(1, $putaran->berkasMinta()->count());

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $pelaksana->id]);
    }

    /** @test */
    public function pelaksana_boleh_mengunggah_hasil_revisi(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $p = $this->putaran($judul);

        $pelaksana = User::factory()->create();
        $pelaksana->assignRole('production');
        $progress->update(['pelaksana_user_id' => $pelaksana->id]);

        $this->actingAs($pelaksana)
            ->post(route('naskah.revisi.hasil', $progress->order_detail_id), [
                'revision_id' => $p->id,
                'berkas'      => [UploadedFile::fake()->create('hasil.docx', 20)],
            ])->assertRedirect()->assertSessionHas('success');

        $this->assertTrue($p->fresh()->terjawab());
    }

    /** @test */
    public function marketing_tidak_boleh_membuka_putaran(): void
    {
        [, $progress] = $this->naskahBerjudul('revisi');

        $mkt = User::factory()->create();
        $mkt->assignRole('marketing');

        // EnforcePermission membalas submit form dengan redirect + flash error, bukan
        // 403 mentah — supaya penolakannya tampil sebagai pesan, bukan halaman galat.
        $this->actingAs($mkt)
            ->post(route('naskah.revisi.minta', $progress->order_detail_id), [
                'request_note' => 'coba buka',
            ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, ManuscriptRevision::count());
    }

    /** @test */
    public function mengembalikan_naskah_lewat_http_membuka_putaran(): void
    {
        [, $progress] = $this->naskahBerjudul('loa');

        $this->actingAs($this->superadmin())
            ->post(route('naskah.kembalikan', $progress->order_detail_id), [
                'alasan' => 'Reviewer minta revisi minor',
            ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('revisi', $progress->fresh()->status);

        $putaran = ManuscriptRevision::first();
        $this->assertSame('revisi', $putaran->stage);
        $this->assertSame('loa', $putaran->from_stage,
            'Asal putaran ditangkap sebelum tahapnya bergerak.');
    }

    /** @test */
    public function menutup_putaran_lewat_http(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $p = $this->putaran($judul);
        $this->berkas($judul, $p, 'revisi_minta');

        $this->actingAs($this->superadmin())
            ->post(route('naskah.revisi.tutup', $progress->order_detail_id), [
                'revision_id' => $p->id,
                'close_note'  => 'Reviewer menarik permintaannya',
            ])->assertRedirect()->assertSessionHas('success');

        $this->assertNotNull($p->fresh()->closed_at);
    }

    // ─── tampilan ───

    /** @test */
    public function kartu_revisi_menampilkan_putaran_lama_saat_mundur_dari_loa(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');

        $lama = $this->putaran($judul, ['closed_at' => now(), 'request_note' => 'Putaran pertama']);
        $lama->files()->create([
            'title_id' => $judul->id, 'slot' => 'revisi_minta', 'status' => 'selesai',
            'version' => 1, 'original_name' => 'putaran-satu.pdf',
        ]);
        $this->putaran($judul, ['request_note' => 'Reviewer minta revisi minor']);

        $isi = $this->actingAs($this->superadmin())
            ->get(route('naskah.show', $progress->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Reviewer minta revisi minor', $isi);
        $this->assertStringContainsString('putaran-satu.pdf', $isi,
            'Berkas putaran lama harus tetap terlist setelah mundur dari LoA.');
        $this->assertStringContainsString('putaran 2', $isi);
    }

    /** @test */
    public function kartu_pada_buku_tidak_berjudul_revisi(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('pembuatan', 'buku');
        $this->putaran($judul, ['stage' => 'pembuatan', 'from_stage' => 'editing']);

        $isi = $this->actingAs($this->superadmin())
            ->get(route('naskah.show', $progress->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Dikembalikan ke Pembuatan', $isi);
    }

    /** @test */
    public function naskah_tanpa_putaran_tak_menampilkan_kartunya(): void
    {
        [, $progress] = $this->naskahBerjudul('editing');

        $isi = $this->actingAs($this->superadmin())
            ->get(route('naskah.show', $progress->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Putaran Perbaikan', $isi,
            'Kartu kosong hanya menambah kebisingan di naskah yang tak pernah direvisi.');
    }

    // ─── pagar ───

    /**
     * autoAdvanceOnUpload() hanya bereaksi pada slot `masuk`, jadi perilakunya sudah
     * benar hari ini. Tes ini memagarinya: menambah slot ke daftar pemicu adalah
     * perubahan satu baris yang akan mendorong naskah ke LoA diam-diam.
     *
     * @test
     */
    public function mengunggah_hasil_revisi_tidak_memajukan_tahap(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $p = $this->putaran($judul);

        app(ManuscriptRevisionService::class)->jawab(
            $p, $this->superadmin(), [UploadedFile::fake()->create('hasil.docx', 20)]
        );

        $this->assertSame('revisi', $progress->fresh()->status,
            'Yang memajukan naskah tetap tombol PJ, bukan kedatangan berkas.');
    }

    /**
     * Papan Pelacakan adalah tempat orang memutuskan mana yang dikerjakan lebih dulu.
     * Naskah yang tertahan menunggu jawaban revisi harus terlihat di situ, bukan baru
     * ketahuan sesudah kartunya dibuka satu per satu.
     *
     * @test
     */
    public function papan_pelacakan_menandai_kartu_yang_punya_putaran_terbuka(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $this->putaran($judul);

        $isi = $this->actingAs($this->superadmin())
            ->get(route('naskah.pelacakan', ['tipe' => 'artikel']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('1 revisi', $isi);
    }

    /** @test */
    public function putaran_yang_sudah_ditutup_tidak_menandai_kartu(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $this->putaran($judul, ['closed_at' => now()]);

        $isi = $this->actingAs($this->superadmin())
            ->get(route('naskah.pelacakan', ['tipe' => 'artikel']))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('revisi</span>', $isi,
            'Putaran yang sudah selesai bukan lagi hal yang menahan siapa pun.');
    }

    /**
     * Slot revisi sah untuk diunggah, tapi SENGAJA tak ikut di daftar per-jenis:
     * berkasnya ditampilkan berkelompok per putaran di kartu Revisi, bukan sebagai
     * baris tetap di kartu berkas.
     *
     * @test
     */
    public function slot_revisi_sah_tapi_tak_muncul_di_kartu_berkas(): void
    {
        $this->assertContains('revisi_minta', ManuscriptFile::slotSah());
        $this->assertContains('revisi_hasil', ManuscriptFile::slotSah());

        $this->assertNotContains('revisi_minta', ManuscriptFile::SLOTS_ARTIKEL);
        $this->assertNotContains('revisi_hasil', ManuscriptFile::SLOTS_BUKU);
    }
}
