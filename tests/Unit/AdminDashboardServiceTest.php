<?php
// tests/Unit/AdminDashboardServiceTest.php

namespace Tests\Unit;

use App\Models\Announcement;
use App\Models\BookIsbn;
use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Models\Title;
use App\Models\TitleArchive;
use App\Models\TitleDocChecklist;
use App\Services\AdminDashboardService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdminDashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        $this->svc = new AdminDashboardService();
    }

    private function title(array $attrs = []): Title
    {
        return Title::create(array_merge([
            'title'       => 'Judul ' . uniqid(),
            'jenis'       => 'buku',
            'tipe_naskah' => 'mandiri',
            'status'      => 'disetujui',
            'slug'        => uniqid(),
        ], $attrs));
    }

    /** @test */
    public function menghitung_buku_yang_dokumennya_belum_diajukan(): void
    {
        $sudah = $this->title();
        TitleDocChecklist::create([
            'title_id' => $sudah->id, 'status' => 'diajukan', 'submitted_at' => now(),
        ]);
        $this->title();                          // buku, belum diajukan → dihitung
        $this->title(['jenis' => 'artikel']);    // artikel → tidak pernah punya checklist

        $this->assertSame(1, $this->svc->forAdmin()['doc_belum_lengkap']);
    }

    /** @test */
    public function menghitung_arsip_yang_masih_draft(): void
    {
        TitleArchive::create(['title_id' => $this->title()->id, 'status' => 'draft']);
        TitleArchive::create(['title_id' => $this->title()->id, 'status' => 'diajukan']);

        $d = $this->svc->forAdmin();
        $this->assertSame(1, $d['arsip_menunggu_artefak']);
        $this->assertSame(1, $d['arsip_diajukan']);
    }

    /** @test */
    public function menghitung_submission_jurnal_aktif_dan_isbn_per_status(): void
    {
        $t = $this->title();
        $j = Journal::create(['nama' => 'Jurnal Uji']);
        JournalSubmission::create(['journal_id' => $j->id, 'title_id' => $t->id, 'status' => 'submitted']);
        JournalSubmission::create(['journal_id' => $j->id, 'title_id' => $t->id, 'status' => 'published']);
        BookIsbn::create(['title_id' => $t->id, 'status' => 'pendaftaran']);
        BookIsbn::create(['title_id' => $this->title()->id, 'status' => 'ber_isbn']);

        $d = $this->svc->forAdmin();
        $this->assertSame(1, $d['jurnal_submission_aktif']);  // submitted+loa, bukan published
        $this->assertSame(1, $d['isbn_pendaftaran']);
        $this->assertSame(1, $d['isbn_ber_isbn']);
        $this->assertSame(0, $d['isbn_cetak']);
    }

    /** @test */
    public function menghitung_pengumuman_aktif(): void
    {
        Announcement::create([
            'title' => 'A', 'body' => 'x', 'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        Announcement::create([
            'title' => 'B', 'body' => 'x', 'status' => 'draft', 'published_at' => null,
        ]);

        $this->assertSame(1, $this->svc->forAdmin()['pengumuman_aktif']);
    }

    /** @test */
    public function tidak_pernah_mengembalikan_angka_uang(): void
    {
        $keys = array_keys($this->svc->forAdmin());
        foreach (['pemasukan', 'income', 'piutang', 'laba', 'saldo', 'komisi'] as $terlarang) {
            foreach ($keys as $k) {
                $this->assertStringNotContainsString($terlarang, $k);
            }
        }
    }
}
