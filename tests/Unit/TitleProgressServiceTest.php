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

    /** @test */
    public function production_advances_editorial_stage(): void
    {
        $p = $this->progress('editing');
        $this->svc->changeStatus($p, 'layout', $this->user('production'));

        $this->assertEquals('layout', $p->fresh()->status);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'event' => 'status_advanced', 'to_value' => 'Layout', 'is_correction' => false,
        ]);
    }

    /** @test */
    public function production_can_hand_off_last_editorial_stage_to_finalization(): void
    {
        $p = $this->progress('isbn'); // next = cetak (superadmin)
        $this->svc->changeStatus($p, 'cetak', $this->user('production'));
        $this->assertEquals('cetak', $p->fresh()->status);
    }

    /** @test */
    public function production_cannot_move_card_after_handoff(): void
    {
        $p = $this->progress('cetak'); // handler superadmin
        $this->expectException(AuthorizationException::class);
        $this->svc->changeStatus($p, 'terbit', $this->user('production'));
    }

    /** @test */
    public function production_correction_requires_note_and_logs_correction(): void
    {
        $p = $this->progress('layout'); // handler production (kartu domain mereka)

        try {
            $this->svc->changeStatus($p, 'editing', $this->user('production')); // mundur tanpa catatan
            $this->fail('Mestinya ValidationException karena catatan kosong.');
        } catch (ValidationException $e) {
            // diharapkan
        }

        $this->svc->changeStatus($p->fresh(), 'editing', $this->user('production'), 'lompat dengan alasan');
        $p->refresh();
        $this->assertEquals('editing', $p->status);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'is_correction' => true,
        ]);
    }

    /** @test */
    public function production_cannot_move_menunggu_proses(): void
    {
        $p = $this->progress('menunggu_proses'); // handler marketing
        $this->expectException(AuthorizationException::class);
        $this->svc->changeStatus($p, 'editing', $this->user('production'));
    }

    /** @test */
    public function manager_advances_any_stage(): void
    {
        $p = $this->progress('menunggu_proses');
        $this->svc->changeStatus($p, 'pembuatan', $this->user('manager'));
        $this->assertEquals('pembuatan', $p->fresh()->status);
    }

    /** @test */
    public function manager_correction_requires_note_and_logs_correction(): void
    {
        $p = $this->progress('layout');

        try {
            $this->svc->changeStatus($p, 'editing', $this->user('manager'));
            $this->fail('Mestinya ValidationException karena catatan kosong.');
        } catch (ValidationException $e) {
            // diharapkan
        }

        $this->svc->changeStatus($p->fresh(), 'editing', $this->user('manager'), 'koreksi manager');
        $p->refresh();
        $this->assertEquals('editing', $p->status);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'is_correction' => true,
        ]);
    }

    /** @test */
    public function superadmin_correction_requires_note(): void
    {
        $p = $this->progress('isbn');
        $this->expectException(ValidationException::class);
        $this->svc->changeStatus($p, 'editing', $this->user('superadmin'), null);
    }

    /** @test */
    public function superadmin_correction_with_note_is_logged(): void
    {
        $p = $this->progress('isbn');
        $this->svc->changeStatus($p, 'editing', $this->user('superadmin'), 'alasan koreksi');
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'event' => 'status_corrected', 'to_value' => 'Editing', 'is_correction' => true,
        ]);
    }

    /** @test */
    public function assign_rejects_user_outside_production_or_manager(): void
    {
        $p = $this->progress('editing');
        $marketing = $this->user('marketing');
        $this->expectException(ValidationException::class);
        $this->svc->assignEditor($p, $marketing->id, $this->user('manager'));
    }

    /** @test */
    public function assign_accepts_production_user_and_null(): void
    {
        $p = $this->progress('editing');
        $editor = $this->user('production');
        $manager = $this->user('manager');

        $this->svc->assignEditor($p, $editor->id, $manager);
        $this->assertEquals($editor->id, $p->fresh()->pelaksana_user_id);

        $this->svc->assignEditor($p, null, $manager);
        $this->assertNull($p->fresh()->pelaksana_user_id);
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
    public function change_status_from_final_stage_is_blocked_for_all_roles(): void
    {
        $p = $this->progress('terbit'); // tahap akhir buku — terminal
        $this->expectException(ValidationException::class);
        $this->svc->changeStatus($p, 'cetak', $this->user('superadmin'), 'mau cetak ulang');
    }

    /** @test */
    public function change_status_rejects_target_invalid_for_type(): void
    {
        $p = $this->progress('editing', 'bk_mandiri'); // 'publish' bukan stage buku
        $this->expectException(ValidationException::class);
        $this->svc->changeStatus($p, 'publish', $this->user('superadmin'));
    }

    /** @test */
    public function correction_with_whitespace_only_note_is_rejected(): void
    {
        $p = $this->progress('isbn');
        $this->expectException(ValidationException::class);
        $this->svc->changeStatus($p, 'editing', $this->user('superadmin'), '   ');
    }

    /** @test */
    public function assign_editor_rejects_unauthorized_actor(): void
    {
        $p = $this->progress('editing');
        $editor = $this->user('production');
        $this->expectException(AuthorizationException::class);
        $this->svc->assignEditor($p, $editor->id, $this->user('marketing'));
    }

    /** @test */
    public function set_priority_rejects_unauthorized_actor(): void
    {
        $p = $this->progress('editing');
        $this->expectException(AuthorizationException::class);
        $this->svc->setPriority($p, 'high', $this->user('marketing'));
    }

    /** @test */
    public function admin_can_be_assigned_as_editor(): void
    {
        $p = $this->progress('editing');
        $admin = $this->user('admin');

        $this->svc->assignEditor($p, $admin->id, $this->user('manager'));

        $this->assertEquals($admin->id, $p->fresh()->pelaksana_user_id);
    }

    /** @test */
    public function admin_can_move_production_stage(): void
    {
        $p = $this->progress('editing'); // handler production
        $this->svc->changeStatus($p, 'layout', $this->user('admin'));

        $this->assertEquals('layout', $p->fresh()->status);
    }

    /**
     * Buat 2 varian order untuk judul yang sama (grup), masing-masing dengan status sendiri.
     */
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
    public function group_advance_brings_all_laggards_to_next_stage(): void
    {
        $grp = $this->group('editing', 'layout'); // kanonik = editing, next = layout
        $this->svc->changeGroupStatus($grp, 'layout', $this->user('production'));

        foreach ($grp as $p) {
            $this->assertEquals('layout', $p->fresh()->status);
        }
    }

    /** @test */
    public function group_advance_blocked_when_canonical_stage_owned_by_superadmin(): void
    {
        $grp = $this->group('cetak', 'terbit'); // kanonik = cetak (superadmin)
        $this->expectException(AuthorizationException::class);
        $this->svc->changeGroupStatus($grp, 'terbit', $this->user('production'));
    }

    /** @test */
    public function superadmin_group_correction_syncs_all_variants(): void
    {
        $grp = $this->group('isbn', 'layout'); // kanonik = layout
        $this->svc->changeGroupStatus($grp, 'editing', $this->user('superadmin'), 'koreksi grup');

        foreach ($grp as $p) {
            $this->assertEquals('editing', $p->fresh()->status);
        }
    }

    /** @test */
    public function group_assign_sets_editor_on_all_variants(): void
    {
        $grp = $this->group('editing', 'editing');
        $editor = $this->user('production');

        $this->svc->assignGroup($grp, $editor->id, $this->user('manager'));

        foreach ($grp as $p) {
            $this->assertEquals($editor->id, $p->fresh()->pelaksana_user_id);
        }
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
    public function editor_priority_target_changes_are_logged(): void
    {
        $p = $this->progress('editing');
        $editor  = $this->user('production');
        $manager = $this->user('manager');

        $this->svc->assignEditor($p, $editor->id, $manager);
        $this->assertDatabaseHas('tb_title_progress_logs', ['title_progress_id' => $p->id, 'event' => 'editor_assigned']);

        $this->svc->setPriority($p, 'high', $manager);
        $this->assertDatabaseHas('tb_title_progress_logs', ['title_progress_id' => $p->id, 'event' => 'priority_changed', 'to_value' => 'High']);

        $this->svc->setTargetDate($p, '2026-09-30', $manager);
        $this->assertDatabaseHas('tb_title_progress_logs', ['title_progress_id' => $p->id, 'event' => 'target_set', 'to_value' => '2026-09-30']);

        $this->assertNotNull($p->fresh()->last_log_at);
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
            'jenis' => $jenis, 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);

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

    /** @test */
    public function clear_logs_requires_superadmin_and_empties_history(): void
    {
        $p = $this->progress('editing');
        $this->svc->setPriority($p, 'high', $this->user('manager')); // 1 log
        $this->assertEquals(1, $p->logs()->count());

        try {
            $this->svc->clearLogs([$p], $this->user('manager')); // bukan superadmin
            $this->fail('Mestinya AuthorizationException.');
        } catch (AuthorizationException $e) {
            // diharapkan
        }

        $this->svc->clearLogs([$p->fresh()], $this->user('superadmin'));
        $this->assertEquals(0, $p->fresh()->logs()->count());
        $this->assertNull($p->fresh()->last_log_at);
    }
}
