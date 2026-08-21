<?php

namespace Tests\Feature;

use App\Models\BookIsbn;
use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Models\Title;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LinkTerbitGateTest extends TestCase
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

    /** @test */
    public function link_di_judul_menang_atas_sumber_lain(): void
    {
        $title = Title::create(['title' => 'Artikel A', 'jenis' => 'artikel',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
                                'link_terbit' => 'https://judul.test/a']);

        $journal = Journal::create(['nama' => 'Jurnal Uji']);
        JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $title->id,
                                   'link_publish' => 'https://submission.test/a']);

        $this->assertSame('https://judul.test/a', $title->fresh()->linkTerbit());
    }

    /** @test */
    public function artikel_tanpa_link_judul_jatuh_ke_direktori_jurnal(): void
    {
        $title   = Title::create(['title' => 'Artikel B', 'jenis' => 'artikel',
                                  'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $journal = Journal::create(['nama' => 'Jurnal Uji']);
        JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $title->id,
                                   'link_publish' => 'https://submission.test/b']);

        $this->assertSame('https://submission.test/b', $title->fresh()->linkTerbit());
    }

    /** @test */
    public function buku_tanpa_link_judul_jatuh_ke_direktori_isbn(): void
    {
        $title = Title::create(['title' => 'Buku C', 'jenis' => 'buku',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        BookIsbn::create(['title_id' => $title->id, 'status' => 'cetak',
                          'link_terbit' => 'https://isbn.test/c']);

        $this->assertSame('https://isbn.test/c', $title->fresh()->linkTerbit());
    }

    /** @test */
    public function tanpa_sumber_mana_pun_bernilai_null(): void
    {
        $title = Title::create(['title' => 'Artikel D', 'jenis' => 'artikel',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);

        $this->assertNull($title->linkTerbit());
    }

    /** @test */
    public function spasi_saja_diperlakukan_sebagai_tidak_ada(): void
    {
        $title = Title::create(['title' => 'Artikel E', 'jenis' => 'artikel',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
                                'link_terbit' => '   ']);

        $this->assertNull($title->fresh()->linkTerbit());
    }

    /**
     * Regresi I3: submission terbaru boleh kosong tanpa mengubur link yang sudah ada
     * di submission lama — kalau tidak, gerbang Terbit akan mengunci naskah yang
     * sebenarnya sudah terbit hanya karena ada baris submission baru yang belum diisi
     * (koreksi jurnal, entri ganda, dsb).
     *
     * @test
     */
    public function submission_lama_yang_berlink_menang_atas_submission_baru_yang_kosong(): void
    {
        $title   = Title::create(['title' => 'Artikel F', 'jenis' => 'artikel',
                                  'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $journal = Journal::create(['nama' => 'Jurnal Uji']);

        JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $title->id,
                                   'link_publish' => 'https://submission.test/lama']);
        JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $title->id,
                                   'link_publish' => null]);

        $this->assertSame('https://submission.test/lama', $title->fresh()->linkTerbit());
    }

    /** @test */
    public function submission_terbaru_menang_ketika_keduanya_berlink(): void
    {
        $title   = Title::create(['title' => 'Artikel G', 'jenis' => 'artikel',
                                  'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $journal = Journal::create(['nama' => 'Jurnal Uji']);

        JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $title->id,
                                   'link_publish' => 'https://submission.test/lama']);
        JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $title->id,
                                   'link_publish' => 'https://submission.test/baru']);

        $this->assertSame('https://submission.test/baru', $title->fresh()->linkTerbit());
    }

    /** @test */
    public function spasi_di_link_judul_jatuh_ke_submission_bukan_berhenti_di_null(): void
    {
        $title   = Title::create(['title' => 'Artikel H', 'jenis' => 'artikel',
                                  'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
                                  'link_terbit' => '   ']);
        $journal = Journal::create(['nama' => 'Jurnal Uji']);
        JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $title->id,
                                   'link_publish' => 'https://submission.test/h']);

        $this->assertSame('https://submission.test/h', $title->fresh()->linkTerbit());
    }

    /** @test */
    public function buku_mengabaikan_journal_submission(): void
    {
        $title   = Title::create(['title' => 'Buku I', 'jenis' => 'buku',
                                  'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $journal = Journal::create(['nama' => 'Jurnal Uji']);
        JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $title->id,
                                   'link_publish' => 'https://submission.test/i']);

        $this->assertNull($title->fresh()->linkTerbit());
    }

    /** @test */
    public function artikel_mengabaikan_book_isbn(): void
    {
        $title = Title::create(['title' => 'Artikel J', 'jenis' => 'artikel',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        BookIsbn::create(['title_id' => $title->id, 'status' => 'cetak',
                          'link_terbit' => 'https://isbn.test/j']);

        $this->assertNull($title->fresh()->linkTerbit());
    }
}
