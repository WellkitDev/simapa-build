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
    public function production_correction_requires_note_and_flags_review(): void
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
        $this->assertTrue($p->needs_review);
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
        $this->svc->changeStatus($p, 'editing', $this->user('manager'));
        $this->assertEquals('editing', $p->fresh()->status);
    }

    /** @test */
    public function manager_correction_requires_note_and_flags_review(): void
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
        $this->assertTrue($p->needs_review);
    }

    /** @test */
    public function superadmin_correction_requires_note(): void
    {
        $p = $this->progress('isbn');
        $this->expectException(ValidationException::class);
        $this->svc->changeStatus($p, 'editing', $this->user('superadmin'), null);
    }

    /** @test */
    public function superadmin_correction_does_not_flag_review(): void
    {
        $p = $this->progress('isbn');
        $this->svc->changeStatus($p, 'editing', $this->user('superadmin'), 'koreksi super');
        $this->assertFalse($p->fresh()->needs_review);
    }

    /** @test */
    public function normal_advance_clears_review_flag(): void
    {
        $p = $this->progress('layout');
        $this->svc->changeStatus($p, 'editing', $this->user('production'), 'lompat'); // koreksi → flag
        $this->assertTrue($p->fresh()->needs_review);

        $this->svc->changeStatus($p->fresh(), 'layout', $this->user('production')); // maju normal → bersih
        $this->assertFalse($p->fresh()->needs_review);
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
        $this->assertEquals($editor->id, $p->fresh()->assigned_user_id);

        $this->svc->assignEditor($p, null, $manager);
        $this->assertNull($p->fresh()->assigned_user_id);
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

        $this->assertEquals($admin->id, $p->fresh()->assigned_user_id);
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
            $this->assertEquals($editor->id, $p->fresh()->assigned_user_id);
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
