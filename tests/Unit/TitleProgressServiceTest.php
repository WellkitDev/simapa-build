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
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
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
            'title_progress_id' => $p->id, 'to_status' => 'layout', 'is_correction' => false,
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
    public function production_cannot_make_correction(): void
    {
        $p = $this->progress('layout');
        $this->expectException(AuthorizationException::class);
        $this->svc->changeStatus($p, 'editing', $this->user('production')); // mundur
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
    public function manager_cannot_make_correction(): void
    {
        $p = $this->progress('layout');
        $this->expectException(AuthorizationException::class);
        $this->svc->changeStatus($p, 'editing', $this->user('manager'));
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
            'title_progress_id' => $p->id, 'to_status' => 'editing', 'is_correction' => true,
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
}
