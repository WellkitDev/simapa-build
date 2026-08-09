<?php
// tests/Unit/AssignmentServiceTest.php

namespace Tests\Unit;

use App\Models\Author;
use App\Models\ChapterProgress;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\GoogleDriveService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private AssignmentService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = app(AssignmentService::class);
    }

    private function user(string $role, ?string $bidang = null): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        if ($bidang !== null) {
            $u->profile()->create(['bidang' => $bidang]);
        }

        return $u->fresh();
    }

    /** Satu judul artikel + N order sejudul (grup) beserta progress-nya. */
    private function progress(string $status = 'menunggu_proses', int $orders = 1, string $bidang = 'artikel'): TitleProgress
    {
        $jenis  = $bidang === 'buku' ? 'buku' : 'artikel';
        $type   = $bidang === 'buku' ? 'bk_mandiri' : 'at_mandiri';
        $title  = Title::create(['title' => 'Judul ' . fake()->unique()->word(), 'jenis' => $jenis,
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);

        $first = null;
        for ($i = 0; $i < $orders; $i++) {
            $detail = OrderDetail::factory()->create([
                'type' => $type, 'title' => $title->title, 'title_id' => $title->id,
            ]);
            $p = TitleProgress::create([
                'order_detail_id' => $detail->id,
                'status'          => $status,
                'assigned_role'   => TitleProgress::getHandlerForStatus($status),
                'bidang'          => $bidang,
                'started_at'      => now(),
            ]);
            $first ??= $p;
        }

        return $first;
    }

    /** Bab buku kolaborasi; $withAuthor=false meniru bab yang author-nya belum dipetakan. */
    private function chapter(bool $withAuthor = true): ChapterProgress
    {
        $book   = Title::create(['title' => 'Buku ' . fake()->unique()->word(), 'jenis' => 'buku',
                                 'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);
        $detail = OrderDetail::factory()->create([
            'type' => 'bk_kolab', 'title' => $book->title, 'title_id' => $book->id, 'chapters' => 3,
        ]);
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'menunggu_proses',
            'assigned_role' => 'marketing', 'bidang' => 'buku', 'started_at' => now(),
        ]);

        $chapter = $book->chapters()->create(['judul' => 'Bab 1', 'urutan' => 1]);
        if ($withAuthor) {
            $chapter->authors()->attach(Author::create(['name' => 'Dr. Rina'])->id, ['position' => 1]);
        }

        return $chapter->progress()->create(['status' => 'menunggu', 'started_at' => now()]);
    }

    /** @test */
    public function admin_mendistribusikan_tugas_ke_akun_produksi(): void
    {
        $admin     = $this->user('admin', 'artikel');
        $pelaksana = $this->user('production');
        $p         = $this->progress('menunggu_proses');

        $affected = $this->svc->distribute($p, $pelaksana->id, $admin);

        $this->assertSame(1, $affected);
        $p->refresh();
        $this->assertSame($pelaksana->id, $p->pelaksana_user_id);
        // Distribusi dari antrian sekaligus memulai tahap Pembuatan + memasang SLA.
        $this->assertSame('pembuatan', $p->status);
        $this->assertNotNull($p->sla_due_at);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'event' => 'distribusi', 'to_value' => $pelaksana->name,
        ]);
    }

    /** @test */
    public function pelaksana_harus_akun_produksi_bukan_admin(): void
    {
        $admin = $this->user('admin', 'artikel');
        $lain  = $this->user('admin', 'artikel');
        $p     = $this->progress();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Pelaksana harus akun Produksi.');
        $this->svc->distribute($p, $lain->id, $admin);
    }

    /** @test */
    public function marketing_tidak_boleh_mendistribusikan(): void
    {
        $p = $this->progress();

        $this->expectException(AuthorizationException::class);
        $this->svc->distribute($p, $this->user('production')->id, $this->user('marketing'));
    }

    /** @test */
    public function produksi_bisa_mengambil_tugas_dari_antrian(): void
    {
        $me = $this->user('production');
        $p  = $this->progress('menunggu_proses');

        $this->svc->claim($p, $me);

        $p->refresh();
        $this->assertSame($me->id, $p->pelaksana_user_id);
        $this->assertSame('pembuatan', $p->status);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'event' => 'claim',
        ]);
    }

    /** @test */
    public function tugas_yang_sudah_berpelaksana_tidak_bisa_diambil(): void
    {
        $p = $this->progress('pembuatan');
        $p->update(['pelaksana_user_id' => $this->user('production')->id]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Tugas ini sudah ada pelaksananya.');
        $this->svc->claim($p, $this->user('production'));
    }

    /** @test */
    public function tarik_tugas_mengosongkan_pelaksana_tanpa_memundurkan_tahap(): void
    {
        $admin = $this->user('admin', 'artikel');
        $p     = $this->progress('pembuatan');
        $p->update(['pelaksana_user_id' => $this->user('production')->id]);

        $this->svc->withdraw($p, $admin);

        $p->refresh();
        $this->assertNull($p->pelaksana_user_id);
        $this->assertNull($p->sla_due_at);
        $this->assertSame('pembuatan', $p->status);
    }

    /** @test */
    public function oper_pj_ke_admin_sebidang_berhasil(): void
    {
        $admin    = $this->user('admin', 'artikel');
        $penerima = $this->user('admin', 'artikel');
        $p        = $this->progress('editing');

        $this->svc->transferPj($p, $penerima->id, $admin);

        $this->assertSame($penerima->id, $p->fresh()->pj_user_id);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'event' => 'oper_pj', 'to_value' => $penerima->name,
        ]);
    }

    /** @test */
    public function oper_pj_lintas_bidang_ditolak_tapi_superadmin_boleh(): void
    {
        $admin       = $this->user('admin', 'artikel');
        $adminBuku   = $this->user('admin', 'buku');
        $p           = $this->progress('editing', 1, 'artikel');

        try {
            $this->svc->transferPj($p, $adminBuku->id, $admin);
            $this->fail('Mestinya ditolak: penerima beda bidang.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('bidang yang sama', collect($e->errors())->flatten()->first());
        }

        $this->svc->transferPj($p, $adminBuku->id, $this->user('superadmin'));
        $this->assertSame($adminBuku->id, $p->fresh()->pj_user_id);
    }

    /** @test */
    public function admin_bidang_lain_tidak_boleh_menyentuh_naskah(): void
    {
        $adminBuku = $this->user('admin', 'buku');
        $p         = $this->progress('menunggu_proses', 1, 'artikel');

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Naskah ini di luar bidang Anda.');
        $this->svc->distribute($p, $this->user('production')->id, $adminBuku);
    }

    /** @test */
    public function bab_tanpa_author_tidak_bisa_didistribusikan(): void
    {
        $cp = $this->chapter(withAuthor: false);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Petakan author bab terlebih dahulu.');
        $this->svc->distribute($cp, $this->user('production')->id, $this->user('admin', 'buku'));
    }

    /** @test */
    public function bab_berauthor_bisa_didistribusikan_dan_masuk_pembuatan(): void
    {
        $cp        = $this->chapter();
        $pelaksana = $this->user('production');

        $this->svc->distribute($cp, $pelaksana->id, $this->user('admin', 'buku'));

        $cp->refresh();
        $this->assertSame($pelaksana->id, $cp->pelaksana_user_id);
        $this->assertSame('pembuatan', $cp->status);
        $this->assertNotNull($cp->sla_due_at);
    }

    /** @test */
    public function sla_tujuh_hari_kerja_melewati_akhir_pekan(): void
    {
        // Senin 10 Agu 2026 + 7 hari kerja → Rabu 19 Agu (Sabtu & Minggu dilewati).
        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00'));

        $p = $this->progress('menunggu_proses');
        $this->svc->distribute($p, $this->user('production')->id, $this->user('admin', 'artikel'));

        $this->assertSame('2026-08-19', $p->fresh()->sla_due_at->toDateString());

        Carbon::setTestNow();
    }

    /** @test */
    public function pembatalan_tanpa_alasan_ditolak(): void
    {
        $admin = $this->user('admin', 'artikel');
        $p     = $this->progress('editing');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Alasan pembatalan wajib diisi.');
        $this->svc->cancel($p, $admin, '   ');
    }

    /** @test */
    public function pembatalan_dengan_alasan_menandai_naskah_dan_tercatat(): void
    {
        $admin = $this->user('admin', 'artikel');
        $p     = $this->progress('editing');

        $this->svc->cancel($p, $admin, 'Klien membatalkan order');

        $p->refresh();
        $this->assertNotNull($p->cancelled_at);
        $this->assertSame($admin->id, $p->cancelled_by);
        $this->assertSame('Klien membatalkan order', $p->cancel_reason);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'event' => 'dibatalkan',
        ]);
    }

    /** @test */
    public function hold_dan_unhold_tercatat(): void
    {
        $admin = $this->user('admin', 'artikel');
        $p     = $this->progress('editing');

        $this->svc->hold($p, $admin, 'Menunggu kelengkapan dari klien');
        $this->assertTrue($p->fresh()->is_on_hold);

        $this->svc->unhold($p, $admin);
        $this->assertFalse($p->fresh()->is_on_hold);

        $this->assertDatabaseHas('tb_title_progress_logs', ['title_progress_id' => $p->id, 'event' => 'hold']);
        $this->assertDatabaseHas('tb_title_progress_logs', ['title_progress_id' => $p->id, 'event' => 'unhold']);
    }

    /** @test */
    public function aksi_berlaku_serempak_untuk_semua_order_sejudul(): void
    {
        $admin     = $this->user('admin', 'artikel');
        $pelaksana = $this->user('production');
        $p         = $this->progress('menunggu_proses', orders: 3);

        $affected = $this->svc->distribute($p, $pelaksana->id, $admin);

        $this->assertSame(3, $affected);
        $group = TitleProgress::whereHas('orderDetail',
            fn ($q) => $q->where('group_key', $p->orderDetail->group_key))->get();
        $this->assertCount(3, $group);
        foreach ($group as $one) {
            $this->assertSame($pelaksana->id, $one->pelaksana_user_id);
            $this->assertSame('pembuatan', $one->status);
        }
        // Audit total: satu baris riwayat per order, bukan satu untuk grup.
        $this->assertSame(3, \App\Models\TitleProgressLog::where('event', 'distribusi')->count());
    }

    /** @test */
    public function admin_tanpa_bidang_belum_terkunci_ke_bidang_mana_pun(): void
    {
        // Kolom user_profiles.bidang baru ada dan belum punya layar pengisian —
        // admin tanpa bidang harus tetap bisa bekerja, bukan terkunci total.
        $admin = $this->user('admin');

        $this->svc->distribute($this->progress('menunggu_proses', 1, 'buku'),
            $this->user('production')->id, $admin);

        $this->assertTrue(true);
    }
}
