<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Models\DailyReport;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TaskPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function employee_can_open_board_and_list(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'Tugasku', 'status' => 'todo', 'priority' => 'normal']);

        $this->actingAs($u)->get(route('task.board'))->assertOk()->assertSee('Tugasku');
        $this->actingAs($u)->get(route('task.index'))->assertOk()->assertSee('Tugasku');
        $this->actingAs($u)->get(route('task.calendar'))->assertOk();
    }

    /** @test */
    public function manager_monitor_shows_all_employees_tasks(): void
    {
        $manager = $this->user('manager');
        $emp = $this->user('production');
        Task::create(['user_id' => $emp->id, 'title' => 'TugasEmp', 'status' => 'todo', 'priority' => 'normal']);

        $this->actingAs($manager)->get(route('task.monitor'))->assertOk()->assertSee('TugasEmp');
    }

    /** @test */
    public function board_marks_locked_done_task(): void
    {
        $u = $this->user('production');
        $today = today();
        Task::create(['user_id' => $u->id, 'title' => 'TugasTerkunci', 'status' => 'done', 'priority' => 'normal', 'completed_at' => $today]);
        DailyReport::create(['user_id' => $u->id, 'report_date' => $today->toDateString(), 'status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($u)->get(route('task.board'))->assertOk()->assertSee('task-locked');
    }

    /**
     * Lampiran harus terlihat dari papan.
     *
     * Berkas yang hanya ketahuan setelah membuka detail sama saja dengan berkas yang
     * tak ada: tak seorang pun membuka tugas untuk memeriksa sesuatu yang belum tentu
     * ada di sana.
     *
     * @test
     */
    public function papan_menandai_tugas_yang_punya_lampiran(): void
    {
        $u = $this->user('production');
        $t = Task::create(['user_id' => $u->id, 'title' => 'AdaBerkas', 'status' => 'todo', 'priority' => 'normal']);
        \App\Models\TaskFile::create([
            'task_id' => $t->id, 'drive_file_id' => 'd1', 'name' => 'hasil.pdf',
            'url' => 'https://drive/x', 'uploaded_by' => $u->id,
        ]);

        $this->actingAs($u)->get(route('task.board'))->assertOk()->assertSee('1 lampiran');
        $this->actingAs($u)->get(route('task.index'))->assertOk()->assertSee('1 lampiran');
    }

    /**
     * Kunci laporan harian menjaga SYARAT tugas, bukan percakapannya.
     *
     * Yang tampil di Laporan Harian cuma judul dan prioritas; utas laporan tak pernah
     * muncul di sana. Menutup formulir laporan tak melindungi apa pun — ia cuma
     * membungkam dua orang yang pekerjaannya belum tentu selesai hanya karena harinya
     * sudah ditutup.
     *
     * @test
     */
    public function halaman_tugas_terkunci_tetap_menampilkan_formulir_laporan(): void
    {
        $u = $this->user('production');
        $today = today();
        $t = Task::create(['user_id' => $u->id, 'title' => 'Beku', 'status' => 'done', 'priority' => 'normal', 'completed_at' => $today]);
        DailyReport::create(['user_id' => $u->id, 'report_date' => $today->toDateString(), 'status' => 'submitted', 'submitted_at' => now()]);

        $this->actingAs($u)->get(route('task.show', $t->id))->assertOk()
            ->assertSee('Tulis laporan')
            ->assertSee(route('task.report', $t->id));
    }

    /**
     * Kotak unggah menghilang tepat di batas yang ditegakkan server: TERKUNCI, bukan
     * `done`.
     *
     * Menampilkan kotak unggah yang pasti ditolak 422 adalah janji yang dipatahkan
     * sendiri oleh aplikasinya — dan orang akan menyalahkan berkasnya, bukan aturannya.
     *
     * @test
     */
    public function kotak_unggah_mengikuti_kunci_bukan_status_selesai(): void
    {
        $u = $this->user('production');

        // Diasersi ELEMENNYA (`id="..."`), bukan namanya: nama itu juga muncul di dalam
        // JS halaman ini sendiri (`getElementById('taskDropzone')`), yang selalu ikut
        // dirender — asersi atas namanya lolos semu di kedua arah.
        $selesai = Task::create(['user_id' => $u->id, 'title' => 'Rampung', 'status' => 'done',
            'priority' => 'normal', 'completed_at' => today()]);
        $this->actingAs($u)->get(route('task.show', $selesai->id))->assertOk()
            ->assertSee('id="taskDropzone"', false);

        // Terkunci → kotaknya hilang, daftar berkasnya tetap terbaca.
        DailyReport::create(['user_id' => $u->id, 'report_date' => today()->toDateString(),
            'status' => 'submitted', 'submitted_at' => now()]);
        $this->actingAs($u)->get(route('task.show', $selesai->id))->assertOk()
            ->assertDontSee('id="taskDropzone"', false)
            ->assertSee('id="taskFiles"', false);
    }
}
