<?php
// tests/Unit/TitleProgressServiceTest.php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\TitleProgressService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    private TitleProgressService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new TitleProgressService();
    }

    private function progress(string $status, string $type = 'bk_mandiri'): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['type' => $type]);
        return TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => $status,
            'assigned_role'   => TitleProgress::getHandlerForStatus($status),
            'started_at'      => now(),
        ]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** Dua order berjudul sama = satu grup judul. */
    private function group(string $status1, string $status2, string $type = 'bk_mandiri', string $title = 'Judul Grup Sama')
    {
        $ids = [];
        foreach ([$status1, $status2] as $st) {
            $detail = OrderDetail::factory()->create(['type' => $type, 'title' => $title]);
            $ids[] = TitleProgress::create([
                'order_detail_id' => $detail->id,
                'status'          => $st,
                'assigned_role'   => TitleProgress::getHandlerForStatus($st),
                'started_at'      => now(),
            ])->id;
        }

        return TitleProgress::with('orderDetail')->whereIn('id', $ids)->get();
    }

    /** @test */
    public function set_priority_validates_value(): void
    {
        $p = $this->progress('editing');
        $manager = $this->user('manager');

        $this->svc->setPriority($p, 'high', $manager);
        $this->assertEquals('high', $p->fresh()->priority);

        $this->expectException(ValidationException::class);
        $this->svc->setPriority($p, 'urgent', $manager);
    }

    /** @test */
    public function set_priority_rejects_unauthorized_actor(): void
    {
        $p = $this->progress('editing');
        $this->expectException(AuthorizationException::class);
        $this->svc->setPriority($p, 'high', $this->user('marketing'));
    }

    /** @test */
    public function group_priority_sets_value_on_all_variants(): void
    {
        $grp = $this->group('editing', 'editing');
        $this->svc->setGroupPriority($grp, 'high', $this->user('manager'));

        foreach ($grp as $p) {
            $this->assertEquals('high', $p->fresh()->priority);
        }
    }

    /** @test */
    public function set_target_date_stores_and_clears(): void
    {
        $p = $this->progress('editing');

        $this->svc->setTargetDate($p, '2026-09-30', $this->user('production'));
        $this->assertEquals('2026-09-30', $p->fresh()->target_date->toDateString());

        $this->svc->setTargetDate($p, '', $this->user('manager'));
        $this->assertNull($p->fresh()->target_date);
    }

    /** @test */
    public function set_target_date_allows_marketing_but_rejects_roleless_user(): void
    {
        $p = $this->progress('editing');

        // Marketing kini boleh set target.
        $this->svc->setTargetDate($p, '2026-09-30', $this->user('marketing'));
        $this->assertEquals('2026-09-30', $p->fresh()->target_date->toDateString());

        // User tanpa role tetap ditolak.
        $noRole = User::factory()->create();
        $this->expectException(AuthorizationException::class);
        $this->svc->setTargetDate($p, '2026-10-01', $noRole);
    }

    /** @test */
    public function group_target_date_sets_on_all_variants(): void
    {
        $grp = $this->group('editing', 'editing');
        $this->svc->setGroupTargetDate($grp, '2026-12-01', $this->user('manager'));

        foreach ($grp as $p) {
            $this->assertEquals('2026-12-01', $p->fresh()->target_date->toDateString());
        }
    }

    /** @test */
    public function no_op_priority_change_is_not_logged(): void
    {
        $p = $this->progress('editing');
        $p->update(['priority' => 'normal']);

        $this->svc->setPriority($p, 'normal', $this->user('manager')); // sama → tak ada log
        $this->assertEquals(0, $p->logs()->where('event', 'priority_changed')->count());
    }
    // ─────────────────────────────────────────────────────────────────────────
    // Penugasan Naskah v2 — advance() / correct() / autoAdvanceOnUpload()
    // ─────────────────────────────────────────────────────────────────────────

    /** Naskah bertaut Title (grup judul) + bidang, seperti data nyata modul baru. */
    private function naskah(string $status, int $orders = 1, string $bidang = 'artikel'): TitleProgress
    {
        $jenis = $bidang === 'buku' ? 'buku' : 'artikel';
        $type  = $bidang === 'buku' ? 'bk_mandiri' : 'at_mandiri';
        $title = \App\Models\Title::create(['title' => 'Naskah ' . fake()->unique()->word(),
            'jenis' => $jenis, 'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
            // Gerbang tahap akhir menuntut alamat terbit. Yang diuji di berkas ini
            // adalah perpindahan tahap, bukan gerbangnya — itu urusan LinkTerbitGateTest.
            'link_terbit' => 'https://uji.test/' . fake()->unique()->slug()]);

        $first = null;
        for ($i = 0; $i < $orders; $i++) {
            $detail = OrderDetail::factory()->create([
                'type' => $type, 'title' => $title->title, 'title_id' => $title->id,
            ]);
            $p = TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => $status,
                'assigned_role' => TitleProgress::getHandlerForStatus($status),
                'bidang' => $bidang, 'started_at' => now(),
            ]);
            $first ??= $p;
        }

        return $first;
    }

    /** @test */
    public function advance_maju_tepat_satu_langkah(): void
    {
        $p = $this->naskah('editing');

        $this->assertSame(1, $this->svc->advance($p, $this->user('admin')));
        $this->assertEquals('revisi', $p->fresh()->status); // ARTICLE_STAGES: editing → revisi
    }

    /** @test */
    public function advance_ditolak_untuk_role_tanpa_izin(): void
    {
        $p = $this->naskah('editing');

        foreach (['production', 'marketing'] as $role) {
            try {
                $this->svc->advance($p, $this->user($role));
                $this->fail("$role mestinya tidak boleh memajukan tahap.");
            } catch (AuthorizationException $e) {
                $this->assertEquals('editing', $p->fresh()->status);
            }
        }
    }

    /** @test */
    public function advance_di_tahap_akhir_ditolak(): void
    {
        $p = $this->naskah('publish');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Naskah sudah berada di tahap akhir.');
        $this->svc->advance($p, $this->user('admin'));
    }

    /** @test */
    public function advance_ke_tahap_final_memindahkan_naskah_ke_arsip(): void
    {
        $p = $this->naskah('loa'); // ARTICLE_STAGES: loa → publish (final)

        $this->svc->advance($p, $this->user('admin'));

        $p->refresh();
        $this->assertEquals('publish', $p->status);
        $this->assertNotNull($p->archived_at, 'Naskah selesai harus pindah ke arsip, bukan hilang.');
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'event' => 'diarsipkan',
        ]);
    }

    /** @test */
    public function advance_berlaku_serempak_untuk_semua_order_sejudul(): void
    {
        $p = $this->naskah('editing', orders: 3);

        $this->assertSame(3, $this->svc->advance($p, $this->user('admin'), 'lanjut'));

        $group = TitleProgress::whereHas('orderDetail',
            fn ($q) => $q->where('group_key', $p->orderDetail->group_key))->get();
        foreach ($group as $one) {
            $this->assertEquals('revisi', $one->fresh()->status);
        }
    }

    /** @test */
    public function correct_wajib_catatan(): void
    {
        $p = $this->naskah('submit');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Catatan wajib diisi untuk koreksi tahap.');
        $this->svc->correct($p, 'editing', $this->user('superadmin'), '   ');
    }

    /** @test */
    public function correct_hanya_superadmin(): void
    {
        $p = $this->naskah('submit');

        foreach (['admin', 'production', 'marketing', 'manager'] as $role) {
            try {
                $this->svc->correct($p, 'editing', $this->user($role), 'mundurkan');
                $this->fail("$role mestinya tidak boleh mengoreksi tahap.");
            } catch (AuthorizationException $e) {
                $this->assertEquals('submit', $p->fresh()->status);
            }
        }

        $this->svc->correct($p, 'editing', $this->user('superadmin'), 'ada revisi mendasar');
        $this->assertEquals('editing', $p->fresh()->status);
    }

    /** @test */
    public function superadmin_bisa_mengoreksi_naskah_yang_sudah_final(): void
    {
        $p = $this->naskah('publish');
        $p->update(['archived_at' => now()]);

        $this->svc->correct($p, 'submit', $this->user('superadmin'), 'salah tandai publish');

        $p->refresh();
        $this->assertEquals('submit', $p->status);
        // Koreksi mundur mengembalikannya ke papan.
        $this->assertNull($p->archived_at);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'event' => 'status_corrected', 'is_correction' => true,
        ]);
    }

    /** @test */
    public function correct_ke_tahap_yang_sama_bukan_error_melainkan_nol_perubahan(): void
    {
        $p = $this->naskah('editing');

        $this->assertSame(0, $this->svc->correct($p, 'editing', $this->user('superadmin'), 'tanpa perubahan'));
        $this->assertEquals('editing', $p->fresh()->status);
    }

    /** @test */
    public function correct_menolak_tahap_di_luar_jenis_naskah(): void
    {
        $p = $this->naskah('editing'); // artikel

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Tahap tidak valid untuk jenis naskah ini.');
        $this->svc->correct($p, 'isbn', $this->user('superadmin'), 'salah tahap');
    }

    /** @test */
    public function upload_naskah_oleh_pelaksana_memajukan_pembuatan_ke_editing(): void
    {
        $pelaksana = $this->user('production');
        $p         = $this->naskah('pembuatan');
        $p->update(['pelaksana_user_id' => $pelaksana->id]);

        $this->assertSame(1, $this->svc->autoAdvanceOnUpload($p, $pelaksana, 'masuk'));

        $this->assertEquals('editing', $p->fresh()->status);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'event' => 'auto_advance_upload',
        ]);
    }

    /** @test */
    public function upload_oleh_orang_lain_tidak_memajukan_tahap_pembuatan(): void
    {
        $p = $this->naskah('pembuatan');
        $p->update(['pelaksana_user_id' => $this->user('production')->id]);

        $this->assertSame(0, $this->svc->autoAdvanceOnUpload($p, $this->user('admin'), 'masuk'));
        $this->assertEquals('pembuatan', $p->fresh()->status);
    }

    /** @test */
    public function naskah_dari_klien_melompati_tahap_pembuatan(): void
    {
        // Order tanpa jasa penulisan: file masuk saat masih Menunggu Proses.
        $p = $this->naskah('menunggu_proses');

        $this->svc->autoAdvanceOnUpload($p, $this->user('marketing'), 'masuk');

        $this->assertEquals('editing', $p->fresh()->status);
    }

    /** @test */
    public function slot_selain_naskah_masuk_tidak_memicu_apa_pun(): void
    {
        $pelaksana = $this->user('production');
        $p         = $this->naskah('pembuatan');
        $p->update(['pelaksana_user_id' => $pelaksana->id]);

        $this->assertSame(0, $this->svc->autoAdvanceOnUpload($p, $pelaksana, 'cover'));
        $this->assertEquals('pembuatan', $p->fresh()->status);
    }

    /**
     * Audit total: riwayat tidak boleh bisa dihapus oleh SIAPA PUN, termasuk superadmin
     * (blueprint wireframe: "Hapus riwayat — tidak ada yang boleh"). Dulu ada
     * TitleProgressService::clearLogs(); test ini menjaga jalur itu tidak kembali.
     *
     * @test
     */
    public function tidak_ada_jalur_untuk_menghapus_riwayat(): void
    {
        $this->assertFalse(
            method_exists($this->svc, 'clearLogs'),
            'Riwayat naskah harus permanen — jangan hidupkan kembali penghapus log.'
        );
    }
}
