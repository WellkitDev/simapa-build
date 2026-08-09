<?php
// tests/Feature/NaskahMigrasiTest.php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\ChapterProgress;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Notifications\DatabaseNotification;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Command migrasi data & penanda keterlambatan.
 *
 * Data lama dibuat sintetis di sini (status 'templating', "editor" yang sebenarnya
 * admin, naskah sudah publish, bab berstatus BOOK_STAGES) — persis bentuk yang akan
 * ditemui di DB produksi saat cutover.
 */
class NaskahMigrasiTest extends TestCase
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

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u->fresh();
    }

    private function progressLama(array $attrs, string $type = 'at_mandiri'): TitleProgress
    {
        $jenis = str_starts_with($type, 'bk_') ? 'buku' : 'artikel';
        $title = Title::create(['title' => 'Lama ' . fake()->unique()->word(), 'jenis' => $jenis,
                                'tipe_naskah' => $type === 'bk_kolab' ? 'kolaborasi' : 'mandiri',
                                'status' => 'disetujui']);
        $order  = Order::factory()->create(['user_id' => $this->user('marketing')->id]);
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => $type,
            'title' => $title->title, 'title_id' => $title->id, 'chapters' => 2,
        ]);

        // Sengaja TANPA bidang — meniru baris yang lahir sebelum kolom itu ada.
        return TitleProgress::create(array_merge([
            'order_detail_id' => $detail->id,
            'assigned_role'   => 'production',
            'started_at'      => now(),
        ], $attrs, ['order_detail_id' => $detail->id]));
    }

    /** @test */
    public function templating_dipindahkan_ke_editing_dan_tercatat_sebagai_koreksi(): void
    {
        $p = $this->progressLama(['status' => 'templating']);

        $this->artisan('naskah:migrate-v2')->assertSuccessful();

        $this->assertSame('editing', $p->fresh()->status);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id,
            'from_value'        => 'Templating',
            'to_value'          => 'Editing',
            'is_correction'     => true,
        ]);
    }

    /** @test */
    public function bidang_diisi_dari_tipe_order(): void
    {
        $artikel = $this->progressLama(['status' => 'editing'], 'at_mandiri');
        $buku    = $this->progressLama(['status' => 'editing'], 'bk_mandiri');

        $this->artisan('naskah:migrate-v2')->assertSuccessful();

        $this->assertSame('artikel', $artikel->fresh()->bidang);
        $this->assertSame('buku', $buku->fresh()->bidang);
    }

    /** @test */
    public function editor_lama_berrole_admin_menjadi_pj_bukan_pelaksana(): void
    {
        $admin     = $this->user('admin');
        $produksi  = $this->user('production');
        $sebagaiPj = $this->progressLama(['status' => 'editing', 'pelaksana_user_id' => $admin->id]);
        $tetap     = $this->progressLama(['status' => 'editing', 'pelaksana_user_id' => $produksi->id]);

        $this->artisan('naskah:migrate-v2')->assertSuccessful();

        $sebagaiPj->refresh();
        $this->assertSame($admin->id, $sebagaiPj->pj_user_id);
        $this->assertNull($sebagaiPj->pelaksana_user_id, 'Akun admin tidak pernah jadi pelaksana.');

        $tetap->refresh();
        $this->assertSame($produksi->id, $tetap->pelaksana_user_id);
        $this->assertNull($tetap->pj_user_id);
    }

    /** @test */
    public function naskah_yang_sudah_selesai_dipindahkan_ke_arsip(): void
    {
        $publish = $this->progressLama(['status' => 'publish']);
        $jalan   = $this->progressLama(['status' => 'editing']);

        $this->artisan('naskah:migrate-v2')->assertSuccessful();

        $this->assertNotNull($publish->fresh()->archived_at);
        $this->assertNull($jalan->fresh()->archived_at);
    }

    /** @test */
    public function status_bab_lama_dipetakan_ke_alur_bab_baru(): void
    {
        $p    = $this->progressLama(['status' => 'editing'], 'bk_kolab');
        $book = $p->orderDetail->titleRef;

        // Bab lama memakai BOOK_STAGES.
        foreach (['menunggu_proses', 'layout', 'terbit'] as $i => $statusLama) {
            $bab = $book->chapters()->create(['judul' => 'Bab ' . ($i + 1), 'urutan' => $i + 1]);
            $bab->authors()->attach(Author::create(['name' => 'Author ' . $i])->id, ['position' => 1]);
            $bab->progress()->create(['status' => $statusLama, 'started_at' => now()]);
        }

        $this->artisan('naskah:migrate-v2')->assertSuccessful();

        $statuses = ChapterProgress::pluck('status')->all();
        $this->assertEqualsCanonicalizing(['menunggu', 'editing', 'selesai'], $statuses);
        foreach ($statuses as $s) {
            $this->assertContains($s, ChapterProgress::CHAPTER_STAGES);
        }
    }

    /** @test */
    public function pelaksana_bab_dipindahkan_dari_kolom_lama(): void
    {
        $p        = $this->progressLama(['status' => 'editing'], 'bk_kolab');
        $book     = $p->orderDetail->titleRef;
        $produksi = $this->user('production');

        $bab = $book->chapters()->create(['judul' => 'Bab 1', 'urutan' => 1]);
        $bab->progress()->create([
            'status' => 'editing', 'assigned_user_id' => $produksi->id, 'started_at' => now(),
        ]);

        $this->artisan('naskah:migrate-v2')->assertSuccessful();

        $this->assertSame($produksi->id, $bab->progress->fresh()->pelaksana_user_id);
    }

    /** @test */
    public function dry_run_tidak_mengubah_apa_pun(): void
    {
        $p = $this->progressLama(['status' => 'templating']);

        $this->artisan('naskah:migrate-v2 --dry-run')->assertSuccessful();

        $this->assertSame('templating', $p->fresh()->status);
        $this->assertNull($p->fresh()->bidang);
    }

    /** @test */
    public function migrasi_idempotent_saat_dijalankan_dua_kali(): void
    {
        $admin = $this->user('admin');
        $this->progressLama(['status' => 'templating', 'pelaksana_user_id' => $admin->id]);

        $this->artisan('naskah:migrate-v2')->assertSuccessful();
        $setelahSekali = TitleProgress::first()->toArray();
        $jumlahLog     = \App\Models\TitleProgressLog::count();

        $this->artisan('naskah:migrate-v2')->assertSuccessful();

        $this->assertSame($setelahSekali['status'], TitleProgress::first()->status);
        $this->assertSame($setelahSekali['pj_user_id'], TitleProgress::first()->pj_user_id);
        $this->assertSame($jumlahLog, \App\Models\TitleProgressLog::count(),
            'Menjalankan ulang migrasi tidak boleh menumpuk baris riwayat.');
    }

    // ─── naskah:check-overdue ───

    /** @test */
    public function check_overdue_mengabari_naskah_lewat_sla_dan_target(): void
    {
        $pj        = $this->user('admin');
        $pelaksana = $this->user('production');

        $lewatSla = $this->progressLama([
            'status' => 'pembuatan', 'bidang' => 'artikel',
            'pj_user_id' => $pj->id, 'pelaksana_user_id' => $pelaksana->id,
            'sla_due_at' => now()->subDays(2),
        ]);
        $tepatWaktu = $this->progressLama([
            'status' => 'pembuatan', 'bidang' => 'artikel',
            'pj_user_id' => $pj->id, 'sla_due_at' => now()->addDays(3),
        ]);

        Notification::fake();
        $this->artisan('naskah:check-overdue')->assertSuccessful();

        Notification::assertSentTo($pj, DatabaseNotification::class);
        Notification::assertSentTo($pelaksana, DatabaseNotification::class);
        $this->assertTrue($lewatSla->fresh()->isOverdue());
        $this->assertFalse($tepatWaktu->fresh()->isOverdue());
    }

    /** @test */
    public function naskah_yang_ditahan_tidak_ikut_ditandai_terlambat(): void
    {
        $pj = $this->user('admin');
        $this->progressLama([
            'status' => 'pembuatan', 'bidang' => 'artikel', 'pj_user_id' => $pj->id,
            'sla_due_at' => now()->subDays(5), 'is_on_hold' => true,
        ]);

        Notification::fake();
        $this->artisan('naskah:check-overdue')
            ->expectsOutputToContain('Tidak ada naskah yang lewat tenggat.')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    /** @test */
    public function naskah_arsip_dan_batal_tidak_dianggap_terlambat(): void
    {
        $pj = $this->user('admin');
        $this->progressLama([
            'status' => 'publish', 'bidang' => 'artikel', 'pj_user_id' => $pj->id,
            'target_date' => now()->subDays(10), 'archived_at' => now(),
        ]);
        $this->progressLama([
            'status' => 'editing', 'bidang' => 'artikel', 'pj_user_id' => $pj->id,
            'target_date' => now()->subDays(10), 'cancelled_at' => now(), 'cancel_reason' => 'batal',
        ]);

        Notification::fake();
        $this->artisan('naskah:check-overdue')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /** @test */
    public function dry_run_check_overdue_tidak_mengirim_notifikasi(): void
    {
        $pj = $this->user('admin');
        $this->progressLama([
            'status' => 'pembuatan', 'bidang' => 'artikel', 'pj_user_id' => $pj->id,
            'sla_due_at' => now()->subDay(),
        ]);

        Notification::fake();
        $this->artisan('naskah:check-overdue --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
