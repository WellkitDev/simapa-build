<?php

namespace Tests\Feature;

use App\Models\ManuscriptFile;
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
 * Putaran perbaikan: permintaan dari PJ, jawaban dari Pelaksana, dan gerbang yang
 * menahan naskah selama permintaan belum dijawab.
 */
class PutaranRevisiTest extends TestCase
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
