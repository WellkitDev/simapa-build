<?php
// tests/Unit/ChapterRollupServiceTest.php

namespace Tests\Unit;

use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\ChapterRollupService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChapterRollupServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChapterRollupService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = app(ChapterRollupService::class);
    }

    /**
     * Buku dengan bab berstatus $statuses. $type membedakan kolaborasi (dipecah per bab)
     * dari mandiri (satu kesatuan, tak ikut roll-up).
     */
    private function book(array $statuses, string $type = 'bk_kolab', string $bookStatus = 'editing'): Title
    {
        $book   = Title::create(['title' => 'Buku ' . fake()->unique()->word(), 'jenis' => 'buku',
                                 'tipe_naskah' => $type === 'bk_kolab' ? 'kolaborasi' : 'mandiri',
                                 'status' => 'disetujui']);
        $detail = OrderDetail::factory()->create([
            'type' => $type, 'title' => $book->title, 'title_id' => $book->id,
            'chapters' => count($statuses),
        ]);
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $bookStatus,
            'assigned_role' => TitleProgress::getHandlerForStatus($bookStatus),
            'bidang' => 'buku', 'started_at' => now(),
        ]);

        foreach ($statuses as $i => $status) {
            $chapter = $book->chapters()->create(['judul' => 'Bab ' . ($i + 1), 'urutan' => $i + 1]);
            $chapter->progress()->create(['status' => $status, 'started_at' => now()]);
        }

        return $book->fresh();
    }

    private function bookStatus(Title $book): string
    {
        return $book->orderDetails()->with('titleProgress')->first()->titleProgress->status;
    }

    /** @test */
    public function bab_yang_belum_digarap_menahan_buku_di_pembuatan(): void
    {
        $book = $this->book(['selesai', 'editing', 'pembuatan', 'menunggu']);

        $this->svc->recalc($book);

        $this->assertSame('pembuatan', $this->bookStatus($book));
        $this->assertFalse($book->orderDetails()->first()->titleProgress->fresh()->chapters_done);
    }

    /** @test */
    public function buku_naik_ke_editing_saat_tak_ada_lagi_bab_yang_dibuat(): void
    {
        $book = $this->book(['selesai', 'editing', 'editing'], bookStatus: 'pembuatan');

        $this->svc->recalc($book);

        $this->assertSame('editing', $this->bookStatus($book));
    }

    /** @test */
    public function semua_bab_selesai_menandai_chapters_done_dan_tercatat_sekali(): void
    {
        $book  = $this->book(['selesai', 'selesai'], bookStatus: 'pembuatan');
        $actor = User::factory()->create();

        $this->svc->recalc($book, $actor);

        $p = $book->orderDetails()->first()->titleProgress->fresh();
        $this->assertTrue($p->chapters_done);
        // Buku TETAP di editing — "Mulai Layout" ditekan admin secara sadar.
        $this->assertSame('editing', $p->status);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'event' => 'chapters_done',
        ]);

        // Idempotent: recalc ulang tidak menumpuk baris riwayat.
        $this->svc->recalc($book, $actor);
        $this->assertSame(1, \App\Models\TitleProgressLog::where('event', 'chapters_done')->count());
    }

    /** @test */
    public function perubahan_bab_tidak_menarik_mundur_buku_yang_sudah_layout(): void
    {
        // Sudah di Layout (level buku). Bab dikoreksi mundur tidak boleh menyeretnya balik.
        $book = $this->book(['selesai', 'editing'], bookStatus: 'layout');

        $this->svc->recalc($book);

        $this->assertSame('layout', $this->bookStatus($book));
    }

    /** @test */
    public function buku_mandiri_tidak_ikut_roll_up_bab(): void
    {
        $book = $this->book(['menunggu', 'menunggu'], type: 'bk_mandiri', bookStatus: 'editing');

        $this->svc->recalc($book);

        $this->assertSame('editing', $this->bookStatus($book));
    }

    /** @test */
    public function ringkasan_bab_menghitung_per_status_dan_persen(): void
    {
        $book = $this->book(['selesai', 'selesai', 'editing', 'pembuatan', 'menunggu', 'menunggu']);

        $s = $this->svc->summary($book);

        $this->assertSame(6, $s['total']);
        $this->assertSame(2, $s['counts']['selesai']);
        $this->assertSame(1, $s['counts']['editing']);
        $this->assertSame(1, $s['counts']['pembuatan']);
        $this->assertSame(2, $s['counts']['menunggu']);
        $this->assertSame(33, $s['persen']);
    }

    /** @test */
    public function chapters_done_hanya_true_saat_seluruh_bab_selesai(): void
    {
        $this->assertFalse($this->svc->chaptersDone($this->book(['selesai', 'editing'])));
        $this->assertTrue($this->svc->chaptersDone($this->book(['selesai', 'selesai'])));
    }
}
