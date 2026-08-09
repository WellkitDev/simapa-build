<?php
// tests/Feature/NaskahNotifikasiTest.php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Notifications\DatabaseNotification;
use App\Services\AssignmentService;
use App\Services\GoogleDriveService;
use App\Services\Notifier;
use App\Services\TitleProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Matriks penerima notifikasi modul Penugasan Naskah (spec §7). Yang dikunci di sini
 * bukan bunyi pesannya, melainkan SIAPA yang dikabari — itu yang jadi keputusan bisnis:
 * tanpa approval, perpindahan langsung jalan, notifikasi yang menjaga semua orang tahu.
 */
class NaskahNotifikasiTest extends TestCase
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

    private function user(string $role, ?string $bidang = null): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        if ($bidang !== null) {
            $u->profile()->create(['bidang' => $bidang]);
        }

        return $u->fresh();
    }

    /** Naskah artikel milik $owner (marketing), dengan PJ opsional. */
    private function naskah(string $status, User $owner, ?User $pj = null): TitleProgress
    {
        $title  = Title::create(['title' => 'Naskah ' . fake()->unique()->word(), 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create(['user_id' => $owner->id]);
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => $title->title, 'title_id' => $title->id,
        ]);

        return TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'assigned_role' => TitleProgress::getHandlerForStatus($status),
            'bidang' => 'artikel', 'pj_user_id' => $pj?->id, 'started_at' => now(),
        ]);
    }

    /** @test */
    public function distribusi_mengabari_pelaksana(): void
    {
        $admin     = $this->user('admin', 'artikel');
        $pelaksana = $this->user('production');
        $p         = $this->naskah('menunggu_proses', $this->user('marketing'));

        Notification::fake();
        app(AssignmentService::class)->distribute($p, $pelaksana->id, $admin);

        Notification::assertSentTo($pelaksana, DatabaseNotification::class);
    }

    /** @test */
    public function tarik_tugas_mengabari_pelaksana_yang_kehilangan_tugas(): void
    {
        $admin     = $this->user('admin', 'artikel');
        $pelaksana = $this->user('production');
        $p         = $this->naskah('pembuatan', $this->user('marketing'));
        $p->update(['pelaksana_user_id' => $pelaksana->id]);

        Notification::fake();
        app(AssignmentService::class)->withdraw($p, $admin);

        Notification::assertSentTo($pelaksana, DatabaseNotification::class);
    }

    /** @test */
    public function claim_mengabari_pj(): void
    {
        $pj = $this->user('admin', 'artikel');
        $me = $this->user('production');
        $p  = $this->naskah('menunggu_proses', $this->user('marketing'), $pj);

        Notification::fake();
        app(AssignmentService::class)->claim($p, $me);

        Notification::assertSentTo($pj, DatabaseNotification::class);
    }

    /** @test */
    public function oper_pj_mengabari_admin_penerima(): void
    {
        $admin    = $this->user('admin', 'artikel');
        $penerima = $this->user('admin', 'artikel');
        $p        = $this->naskah('editing', $this->user('marketing'), $admin);

        Notification::fake();
        app(AssignmentService::class)->transferPj($p, $penerima->id, $admin);

        Notification::assertSentTo($penerima, DatabaseNotification::class);
    }

    /** @test */
    public function maju_tahap_mengabari_pj_dan_superadmin_bukan_marketing(): void
    {
        $owner      = $this->user('marketing');
        $pj         = $this->user('admin', 'artikel');
        $superadmin = $this->user('superadmin');
        $p          = $this->naskah('editing', $owner, $pj);

        Notification::fake();
        app(TitleProgressService::class)->advance($p, $pj);

        Notification::assertSentTo($superadmin, DatabaseNotification::class);
        // Marketing sengaja TIDAK dikabari tiap tahap — hanya saat publish/terbit.
        Notification::assertNotSentTo($owner, DatabaseNotification::class);
    }

    /** @test */
    public function publish_mengabari_marketing_pemilik_tiap_order_dalam_grup(): void
    {
        $ownerA = $this->user('marketing');
        $ownerB = $this->user('marketing');
        $pj     = $this->user('admin', 'artikel');

        // Dua order sejudul, pemilik berbeda → keduanya harus dapat kabar.
        $p     = $this->naskah('loa', $ownerA, $pj);
        $title = $p->orderDetail->titleRef;
        $orderB = Order::factory()->create(['user_id' => $ownerB->id]);
        $detailB = OrderDetail::factory()->create([
            'order_id' => $orderB->id, 'type' => 'at_mandiri',
            'title' => $title->title, 'title_id' => $title->id,
        ]);
        TitleProgress::create([
            'order_detail_id' => $detailB->id, 'status' => 'loa',
            'assigned_role' => 'superadmin', 'bidang' => 'artikel',
            'pj_user_id' => $pj->id, 'started_at' => now(),
        ]);

        Notification::fake();
        app(TitleProgressService::class)->advance($p, $pj); // loa → publish (final)

        Notification::assertSentTo($ownerA, DatabaseNotification::class);
        Notification::assertSentTo($ownerB, DatabaseNotification::class);
    }

    /** @test */
    public function lewat_tenggat_mengabari_pj_pelaksana_dan_superadmin(): void
    {
        $pj         = $this->user('admin', 'artikel');
        $pelaksana  = $this->user('production');
        $superadmin = $this->user('superadmin');
        $p          = $this->naskah('pembuatan', $this->user('marketing'), $pj);
        $p->update(['pelaksana_user_id' => $pelaksana->id, 'sla_due_at' => now()->subDays(2)]);

        Notification::fake();
        app(Notifier::class)->naskahOverdue($p->fresh());

        foreach ([$pj, $pelaksana, $superadmin] as $orang) {
            Notification::assertSentTo($orang, DatabaseNotification::class);
        }
    }

    /** @test */
    public function upload_naskah_mengabari_pj(): void
    {
        $pj        = $this->user('admin', 'artikel');
        $pelaksana = $this->user('production');
        $p         = $this->naskah('pembuatan', $this->user('marketing'), $pj);
        $p->update(['pelaksana_user_id' => $pelaksana->id]);

        Notification::fake();
        app(TitleProgressService::class)->autoAdvanceOnUpload($p->fresh(), $pelaksana, 'masuk');

        Notification::assertSentTo($pj, DatabaseNotification::class);
    }
}
