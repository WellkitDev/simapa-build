<?php
// tests/Feature/BabProgressSelaluAdaTest.php

namespace Tests\Feature;

use App\Models\ChapterProgress;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\TitleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Setiap bab WAJIB punya ChapterProgress.
 *
 * Kolom Pelaksana, Status, Lama, dan Aksi di tabel bab semuanya dibaca dari baris itu.
 * Bab tanpa progress karena itu tampil sebagai deretan strip — dan tak ada satu pun
 * tombol untuk memperbaikinya dari layar.
 *
 * Dulu progress hanya lahir di `ChapterManuscriptService::ensureChapters()`, yang
 * dipanggil DARI SATU TEMPAT SAJA: saat TitleProgress sebuah order dibuat. Menyimpan
 * formulir judul lewat `TitleService::syncChapters()` membuat bab tanpa pernah membuat
 * progressnya, jadi bab yang lahir sesudah ordernya dipesan tak pernah punya satu pun.
 *
 * Authornya selamat karena `ChapterAuthorService::seedFromOrders()` berjalan tiap kali
 * halaman judul dibuka. Progress tak punya penjaga serupa — itulah kenapa gejalanya
 * "Author terisi, sisanya strip".
 *
 * Ditemukan di data produksi 2026-08-25: judul 95 (AIDP) — 6 bab, nol progress.
 */
class BabProgressSelaluAdaTest extends TestCase
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

    private function aktor(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    /** Buku kolaborasi yang ordernya sudah berjalan di tahap tertentu. */
    private function buku(int $bab = 3, string $tahapOrder = 'pembuatan'): Title
    {
        $t = Title::create([
            'title' => 'Buku ' . fake()->unique()->words(3, true),
            'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui',
        ]);

        for ($i = 1; $i <= $bab; $i++) {
            $c = $t->chapters()->create(['judul' => "Bab {$i}", 'urutan' => $i]);
            $c->progress()->create(['status' => 'menunggu', 'started_at' => now()]);
        }

        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'bk_kolab',
            'title' => $t->title, 'title_id' => $t->id, 'chapters' => $bab,
        ]);
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $tahapOrder,
            'assigned_role' => TitleProgress::getHandlerForStatus($tahapOrder),
            'bidang' => 'buku', 'started_at' => now(),
        ]);

        return $t->fresh();
    }

    /** Bentuk `chapters[]` seperti yang dikirim formulir judul. */
    private function payloadBab(Title $t, array $judulBaru = []): array
    {
        $bab = $t->chapters()->orderBy('urutan')->get()
            ->map(fn ($c) => ['id' => $c->id, 'judul' => $c->judul])->all();

        foreach ($judulBaru as $j) {
            $bab[] = ['id' => null, 'judul' => $j];
        }

        return $bab;
    }

    /** @test */
    public function bab_baru_yang_disimpan_lewat_formulir_judul_langsung_punya_progress(): void
    {
        $t = $this->buku(3);

        app(TitleService::class)->update(
            $t,
            ['title' => $t->title, 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi'],
            $this->payloadBab($t, ['Bab 4']),
            $this->aktor()
        );

        $bab4 = $t->fresh()->chapters()->where('urutan', 4)->first();

        $this->assertNotNull($bab4, 'Bab 4 harus terbentuk.');
        $this->assertNotNull(
            $bab4->progress,
            'Bab tanpa ChapterProgress tampil sebagai strip di Pelacakan, tanpa tombol apa pun untuk memperbaikinya.'
        );
    }

    /** @test */
    public function menyimpan_judul_memulihkan_bab_yang_progressnya_hilang(): void
    {
        $t = $this->buku(3);

        // Bentuk yang tertinggal di produksi: babnya ada, progressnya musnah
        // (dulu lewat cascade saat syncChapters menghapus-buat-ulang seluruh bab).
        ChapterProgress::query()->delete();
        $this->assertSame(0, ChapterProgress::count());

        app(TitleService::class)->update(
            $t,
            ['title' => $t->title, 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi'],
            $this->payloadBab($t),
            $this->aktor()
        );

        $this->assertSame(3, ChapterProgress::count(),
            'Menyimpan judul harus memulihkan progress yang hilang, bukan membiarkannya.');
    }

    /**
     * Semaian TIDAK boleh memakai kosakata TitleProgress. `CHAPTER_STAGES` hanya
     * mengenal empat nilai; buku yang ordernya di `layout` atau `proofreading` akan
     * menulis status yang tak ada dalam daftar itu, dan `nextStage()` mengembalikan
     * null sehingga babnya terkunci selamanya.
     *
     * @test
     */
    public function status_semaian_selalu_dari_kosakata_bab(): void
    {
        foreach (['menunggu_proses', 'pembuatan', 'layout', 'proofreading', 'terbit'] as $tahap) {
            $t = $this->buku(2, tahapOrder: $tahap);
            ChapterProgress::whereIn(
                'title_chapter_id',
                $t->chapters()->pluck('id')
            )->delete();

            app(TitleService::class)->update(
                $t,
                ['title' => $t->title, 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi'],
                $this->payloadBab($t),
                $this->aktor()
            );

            foreach ($t->fresh()->chapters as $c) {
                $this->assertContains(
                    $c->progress?->status,
                    ChapterProgress::CHAPTER_STAGES,
                    "Order di tahap '{$tahap}' menyemai status bab yang tak dikenal: " . var_export($c->progress?->status, true)
                );
            }
        }
    }

    /** Progress yang sudah ada tak boleh disentuh — statusnya milik pekerjaan nyata. */
    /** @test */
    public function progress_yang_sudah_ada_tidak_ditimpa(): void
    {
        $t = $this->buku(3);
        $bab1 = $t->chapters()->where('urutan', 1)->first();
        $bab1->progress->update(['status' => 'selesai']);

        app(TitleService::class)->update(
            $t,
            ['title' => $t->title, 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi'],
            $this->payloadBab($t, ['Bab 4']),
            $this->aktor()
        );

        $this->assertSame('selesai', $bab1->fresh()->progress->status,
            'Kemajuan bab yang sudah dikerjakan orang tak boleh dikembalikan ke awal.');
    }

    /** @test */
    public function judul_buku_baru_pun_babnya_langsung_punya_progress(): void
    {
        $t = app(TitleService::class)->create(
            ['title' => 'Buku Baru ' . fake()->unique()->words(2, true),
             'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi'],
            [['id' => null, 'judul' => 'Bab 1'], ['id' => null, 'judul' => 'Bab 2']],
            $this->aktor()
        );

        $this->assertSame(2, $t->chapters()->count());
        foreach ($t->chapters as $c) {
            $this->assertNotNull($c->progress, "Bab '{$c->judul}' lahir tanpa progress.");
        }
    }

    /**
     * Penjaga yang sebenarnya, ditulis sebagai keadaan seluruh basis data: TIDAK BOLEH
     * ADA bab tanpa progress, apa pun jalan yang ditempuh untuk membuatnya.
     *
     * @test
     */
    public function tak_ada_satu_pun_bab_tanpa_progress_sesudah_judul_disimpan(): void
    {
        $t = $this->buku(4);
        ChapterProgress::query()->delete();

        app(TitleService::class)->update(
            $t,
            ['title' => $t->title . ' (revisi)', 'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi'],
            $this->payloadBab($t, ['Bab 5', 'Bab 6']),
            $this->aktor()
        );

        $yatim = \App\Models\TitleChapter::doesntHave('progress')->count();

        $this->assertSame(0, $yatim, "{$yatim} bab tak punya progress — kolom Pelaksana/Status/Lama/Aksi akan jadi strip.");
    }
}
