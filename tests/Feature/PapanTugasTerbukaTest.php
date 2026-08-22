<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Notifications\DatabaseNotification;
use App\Services\Notifier;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Papan Tugas terbuka, dan tenggangnya akhirnya berbunyi.
 *
 * Menugaskan dulu digerbangi `manager|superadmin`, sementara di produksi TAK ADA satu
 * pun akun manager (admin 6 · marketing 2 · production 4 · superadmin 1) — praktis hanya
 * satu orang di seluruh kantor yang bisa membagi pekerjaan.
 *
 * Dan `TaskService::notifyDueSoon()` sudah benar sejak modulnya dibuat, tapi tak ada
 * satu pun yang memanggilnya: pengingat tenggang tak pernah sekali pun berbunyi.
 */
class PapanTugasTerbukaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function tugas(User $untuk, array $extra = []): Task
    {
        return Task::create(array_merge([
            'user_id' => $untuk->id, 'title' => 'Rapikan berkas',
            'status' => 'todo', 'priority' => 'normal',
        ], $extra));
    }

    // ─── semua orang boleh menugaskan ───

    /** @test */
    public function produksi_bisa_memberi_tugas_ke_orang_lain(): void
    {
        Notification::fake();

        $budi  = $this->user('production');
        $citra = $this->user('marketing');

        $this->actingAs($budi)->post(route('task.store'), [
            'title' => 'Tolong siapkan berkas', 'priority' => 'normal',
            'assignee' => $citra->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_tasks', [
            'title' => 'Tolong siapkan berkas',
            'user_id' => $citra->id,
            'created_by' => $budi->id,
        ]);

        Notification::assertSentTo($citra, DatabaseNotification::class);
    }

    /** @test */
    public function marketing_melihat_daftar_penerima_tugas(): void
    {
        $mkt = $this->user('marketing');
        $this->user('production');

        $daftar = $this->actingAs($mkt)->get(route('task.board'))
            ->assertOk()->viewData('assignees');

        $this->assertGreaterThanOrEqual(2, $daftar->count(),
            'Setiap pengguna harus melihat daftar orang yang bisa diberi tugas.');
    }

    /**
     * Memberi tugas tanpa bisa melihat hasilnya adalah setengah fitur.
     *
     * @test
     */
    public function pemberi_tugas_bisa_melihat_papan_penerimanya(): void
    {
        $budi  = $this->user('production');
        $citra = $this->user('marketing');
        $this->tugas($citra, ['title' => 'Punya Citra']);

        $this->actingAs($budi)
            ->get(route('task.board', ['user_id' => $citra->id]))
            ->assertOk()
            ->assertSee('Punya Citra');
    }

    /**
     * Yang salah ketik saat memberi tugas harus bisa memperbaikinya sendiri.
     *
     * @test
     */
    public function pembuat_tugas_boleh_menyuntingnya_meski_bukan_pemiliknya(): void
    {
        Notification::fake();

        $budi  = $this->user('production');
        $citra = $this->user('marketing');
        $task  = $this->tugas($citra, ['created_by' => $budi->id]);

        $this->actingAs($budi)->put(route('task.update', $task->id), [
            'title' => 'Judul diperbaiki', 'priority' => 'high',
        ])->assertRedirect();

        $this->assertSame('Judul diperbaiki', $task->fresh()->title);
    }

    /** @test */
    public function orang_luar_tetap_tak_boleh_menyunting_tugas_orang_lain(): void
    {
        $citra = $this->user('marketing');
        $dedi  = $this->user('production');
        $task  = $this->tugas($citra, ['created_by' => $citra->id]);

        $this->actingAs($dedi)->put(route('task.update', $task->id), [
            'title' => 'Diam-diam diubah', 'priority' => 'low',
        ])->assertForbidden();

        $this->assertSame('Rapikan berkas', $task->fresh()->title);
    }

    // ─── tangga tenggang ───

    /** @test */
    public function tahap_tenggang_dihitung_dari_selisih_hari(): void
    {
        $u = $this->user('production');

        $peta = [
            'lewat'     => today()->subDays(3),
            'hari_ini'  => today(),
            'mendekati' => today()->addDays(2),
            null        => today()->addDays(10),
        ];

        foreach ($peta as $harapan => $tanggal) {
            $t = $this->tugas($u, ['due_date' => $tanggal]);
            $this->assertSame($harapan ?: null, TaskService::tahapTenggang($t));
        }
    }

    /** @test */
    public function tugas_lewat_tenggang_akhirnya_diingatkan(): void
    {
        Notification::fake();

        $budi = $this->user('production');
        $t = $this->tugas($budi, ['due_date' => today()->subDays(5)]);

        app(TaskService::class)->notifyDueSoon(app(Notifier::class));

        $this->assertSame('lewat', $t->fresh()->deadline_stage);
        Notification::assertSentTo($budi, DatabaseNotification::class,
            fn ($n) => str_contains($n->payload['title'], 'LEWAT'));
    }

    /**
     * Tiap tahap berbunyi TEPAT SEKALI meski perintahnya jalan tiap hari.
     *
     * @test
     */
    public function tahap_yang_sama_tak_diulang(): void
    {
        Notification::fake();

        $budi = $this->user('production');
        $this->tugas($budi, ['due_date' => today()->addDay()]);

        $svc = app(TaskService::class);
        $this->assertSame(1, $svc->notifyDueSoon(app(Notifier::class)));
        $this->assertSame(0, $svc->notifyDueSoon(app(Notifier::class)),
            'Menjalankan perintahnya dua kali tak boleh mengirim pengingat kedua.');
    }

    /** @test */
    public function tahap_berganti_saat_tenggangnya_terlewati(): void
    {
        Notification::fake();

        $budi = $this->user('production');
        $t = $this->tugas($budi, ['due_date' => today()->addDay()]);
        $svc = app(TaskService::class);

        $svc->notifyDueSoon(app(Notifier::class));
        $this->assertSame('mendekati', $t->fresh()->deadline_stage);

        // Besok lusa: tenggangnya sudah lewat.
        $t->update(['due_date' => today()->subDay()]);
        $this->assertSame(1, $svc->notifyDueSoon(app(Notifier::class)));
        $this->assertSame('lewat', $t->fresh()->deadline_stage);
    }

    /** @test */
    public function tugas_selesai_tak_diingatkan(): void
    {
        Notification::fake();

        $budi = $this->user('production');
        $this->tugas($budi, ['due_date' => today()->subDays(2), 'status' => 'done']);

        $this->assertSame(0, app(TaskService::class)->notifyDueSoon(app(Notifier::class)));
    }

    /** @test */
    public function perintah_tenggang_terdaftar_dan_bisa_dijalankan(): void
    {
        $this->artisan('tasks:check-deadline')
            ->expectsOutputToContain('Tak ada pengingat')
            ->assertSuccessful();
    }
}
