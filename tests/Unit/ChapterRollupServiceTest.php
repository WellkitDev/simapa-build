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

    // ─────────────────────────────────────────────────────────────────────────
    // Upload naskah → maju otomatis (end-to-end lewat ManuscriptFileService)
    // ─────────────────────────────────────────────────────────────────────────

    private function pelaksana(): User
    {
        $u = User::factory()->create();
        $u->assignRole('production');

        return $u;
    }

    /** GoogleDriveService di-mock; unggahan cukup mengembalikan id/url palsu. */
    private function upload(Title $title, $chapter, string $slot, User $actor): void
    {
        $this->mock(\App\Services\GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'drive-1', 'url' => 'https://drive/1']);
        });

        app(\App\Services\ManuscriptFileService::class)->upload(
            $title,
            $chapter,
            $slot,
            \Illuminate\Http\UploadedFile::fake()->create('naskah.docx', 12),
            $actor
        );
    }

    /** @test */
    public function upload_naskah_bab_oleh_pelaksana_memajukan_bab_dan_menghitung_ulang_buku(): void
    {
        $book = $this->book(['pembuatan', 'menunggu'], bookStatus: 'pembuatan');
        $ch1  = $book->chapters()->with('progress')->orderBy('urutan')->first();
        $me   = $this->pelaksana();
        $ch1->progress->update(['pelaksana_user_id' => $me->id]);

        $this->upload($book, $ch1, 'masuk', $me);

        $this->assertSame('editing', $ch1->progress->fresh()->status);
        // Bab 2 masih Menunggu → buku tetap tertahan di Pembuatan.
        $this->assertSame('pembuatan', $this->bookStatus($book));
    }

    /** @test */
    public function bab_terakhir_selesai_membuka_tahap_layout(): void
    {
        $book = $this->book(['selesai', 'editing'], bookStatus: 'editing');
        $ch2  = $book->chapters()->with('progress')->orderBy('urutan')->get()->last();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        app(\App\Services\TitleProgressService::class)->advanceChapter($ch2->progress, $admin);

        $p = $book->orderDetails()->first()->titleProgress->fresh();
        $this->assertSame('selesai', $ch2->progress->fresh()->status);
        $this->assertTrue($p->chapters_done, 'Semua bab selesai → tombol Mulai Layout terbuka.');

        // Sekarang buku boleh maju ke Layout.
        app(\App\Services\TitleProgressService::class)->advance($p, $admin);
        $this->assertSame('layout', $p->fresh()->status);
    }

    /** @test */
    public function layout_terkunci_selama_masih_ada_bab_berjalan(): void
    {
        $book  = $this->book(['selesai', 'editing'], bookStatus: 'editing');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $p = $book->orderDetails()->first()->titleProgress;

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('Semua bab harus Selesai dulu sebelum masuk tahap Layout.');
        app(\App\Services\TitleProgressService::class)->advance($p, $admin);
    }

    /** @test */
    public function buku_mandiri_maju_ke_layout_tanpa_syarat_bab(): void
    {
        $book  = $this->book(['menunggu'], type: 'bk_mandiri', bookStatus: 'editing');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $p = $book->orderDetails()->first()->titleProgress;

        app(\App\Services\TitleProgressService::class)->advance($p, $admin);

        $this->assertSame('layout', $p->fresh()->status);
    }

    /** @test */
    public function upload_naskah_level_judul_memajukan_artikel_ke_editing(): void
    {
        $title = Title::create(['title' => 'Artikel Upload', 'jenis' => 'artikel',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $detail = OrderDetail::factory()->create([
            'type' => 'at_mandiri', 'title' => $title->title, 'title_id' => $title->id,
        ]);
        $me = $this->pelaksana();
        $p  = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'pembuatan',
            'assigned_role' => 'production', 'bidang' => 'artikel',
            'pelaksana_user_id' => $me->id, 'started_at' => now(),
        ]);

        $this->upload($title, null, 'masuk', $me);

        $this->assertSame('editing', $p->fresh()->status);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'event' => 'auto_advance_upload',
        ]);
    }
}
