<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DeadlineAlertTest extends TestCase
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
    public function dashboard_shows_deadline_card_and_notifies_once(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'DEADLINE DEKAT', 'status' => 'todo', 'priority' => 'normal', 'due_date' => today()->addDays(2)->toDateString()]);

        $this->actingAs($u)->get(route('dashboard'))->assertOk()->assertSee('DEADLINE DEKAT');

        // notifikasi dibuat sekali (idempoten saat dashboard dibuka lagi)
        $this->assertSame(1, $u->notifications()->count());
        $this->actingAs($u)->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $u->notifications()->count());
    }

    /**
     * Kartu deadline melipat sendiri setelah beberapa detik supaya dashboard tak sesak.
     * Tapi yang sudah LEWAT atau jatuh tempo HARI INI tak boleh ikut — menyembunyikan
     * lencana merah setelah dua belas detik bukan merapikan tampilan, itu menyembunyikan
     * pekerjaan yang sudah terlambat.
     *
     * Penandanya elemen hitungan mundur: ada = boleh melipat sendiri, tak ada = bertahan
     * sampai orangnya sendiri yang menutup.
     *
     * @test
     */
    public function tugas_mendesak_tidak_ikut_melipat_sendiri(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'SUDAH LEWAT', 'status' => 'todo',
                      'priority' => 'normal', 'due_date' => today()->subDay()->toDateString()]);

        $this->actingAs($u)->get(route('dashboard'))->assertOk()
            ->assertSee('SUDAH LEWAT')
            ->assertSee('Lewat 1h')
            ->assertSee('data-deadline-card', escape: false)
            ->assertDontSee('data-autohide="12"', escape: false);
    }

    /**
     * Tugas yang sudah lewat tenggang DULU tak pernah muncul di kartu ini: batas
     * bawahnya `today`, jadi yang paling mendesak justru terbuang. Label "Lewat Nh" di
     * partialnya karena itu kode mati, dan notifikasi berbunyi "⚠ Tugas LEWAT tenggang"
     * tentang sesuatu yang tak pernah terlihat di layar.
     *
     * @test
     */
    public function tugas_yang_sudah_lewat_ikut_ditampilkan(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'TERLAMBAT SEMINGGU', 'status' => 'todo',
                      'priority' => 'normal', 'due_date' => today()->subDays(7)->toDateString()]);

        $this->actingAs($u)->get(route('dashboard'))->assertOk()
            ->assertSee('TERLAMBAT SEMINGGU')
            ->assertSee('Lewat 7h');
    }

    /**
     * Pemberi tugas melihat tenggat tugas yang IA BERIKAN, bukan cuma yang diberikan
     * kepadanya. Sejak semua orang boleh menugaskan, tanpa ini ada orang yang bisa
     * memberi tugas tapi tak punya satu pun cara melihat tenggatnya.
     *
     * @test
     */
    public function pemberi_tugas_melihat_tenggat_tugas_yang_ia_berikan(): void
    {
        $pemberi   = $this->user('marketing');
        $pelaksana = $this->user('production');

        Task::create(['user_id' => $pelaksana->id, 'created_by' => $pemberi->id,
                      'title' => 'TITIP KE PRODUKSI', 'status' => 'todo', 'priority' => 'normal',
                      'due_date' => today()->addDays(3)->toDateString()]);

        $this->actingAs($pemberi)->get(route('dashboard'))->assertOk()
            ->assertSee('TITIP KE PRODUKSI')
            ->assertSee('diberikan ke');
    }

    /** Orang yang tak terlibat tetap tak melihatnya. */
    /** @test */
    public function orang_luar_tidak_melihat_tugas_yang_bukan_urusannya(): void
    {
        $pelaksana = $this->user('production');
        Task::create(['user_id' => $pelaksana->id, 'created_by' => $pelaksana->id,
                      'title' => 'BUKAN URUSANMU', 'status' => 'todo', 'priority' => 'normal',
                      'due_date' => today()->addDays(3)->toDateString()]);

        $this->actingAs($this->user('marketing'))->get(route('dashboard'))
            ->assertOk()->assertDontSee('BUKAN URUSANMU');
    }

    /**
     * `admin` dicabut dari daftar pengawas 2026-08-26 atas izin user: enam akun admin
     * yang mengawasi seluruh tugas di kantor 13 orang lebih terasa sebagai kebisingan
     * daripada pengawasan.
     *
     * @test
     */
    public function admin_tidak_lagi_melihat_tugas_seluruh_kantor(): void
    {
        $orang = $this->user('production');
        Task::create(['user_id' => $orang->id, 'created_by' => $orang->id,
                      'title' => 'TUGAS ORANG LAIN', 'status' => 'todo', 'priority' => 'normal',
                      'due_date' => today()->addDays(3)->toDateString()]);

        // Termasuk lewat lonceng notifikasi: mencabut admin dari kartunya saja justru
        // meninggalkan saluran yang lebih berisik.
        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()->assertDontSee('TUGAS ORANG LAIN');
        $this->assertSame(0, $admin->notifications()->count());

        $this->actingAs($this->user('superadmin'))->get(route('dashboard'))
            ->assertOk()->assertSee('TUGAS ORANG LAIN');
    }

    /** @test */
    public function tugas_yang_belum_mendesak_melipat_sendiri(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'MASIH LAMA', 'status' => 'todo',
                      'priority' => 'normal', 'due_date' => today()->addDays(5)->toDateString()]);

        $this->actingAs($u)->get(route('dashboard'))->assertOk()
            ->assertSee('data-autohide="12"', escape: false);
    }

    /**
     * Kartunya dilipat, bukan dibuang: pil ringkas tetap ada supaya orang bisa
     * membukanya lagi. Menghilangkan informasinya sama sekali bukan merapikan.
     *
     * @test
     */
    public function ada_jalan_membuka_kembali_kartu_yang_disembunyikan(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'MASIH LAMA', 'status' => 'todo',
                      'priority' => 'normal', 'due_date' => today()->addDays(5)->toDateString()]);

        $this->actingAs($u)->get(route('dashboard'))->assertOk()
            ->assertSee('data-deadline-pill', escape: false)
            ->assertSee('data-deadline-close', escape: false);
    }

    /**
     * Popup SweetAlert dihapus: ia menampilkan daftar yang PERSIS SAMA dengan kartu di
     * bawahnya, dan modal yang menghalangi seluruh halaman adalah bentuk kebisingan
     * paling mahal di dashboard yang justru ingin dibuat lebih lapang.
     *
     * @test
     */
    public function tidak_ada_lagi_modal_yang_mengulang_isi_kartunya(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'MASIH LAMA', 'status' => 'todo',
                      'priority' => 'normal', 'due_date' => today()->addDays(5)->toDateString()]);

        $isi = $this->actingAs($u)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('deadlineAlertShown', $isi,
            'Modal deadline muncul lagi — daftarnya sudah ada di kartu tepat di bawahnya.');
    }

    /** @test */
    public function no_deadline_card_when_nothing_due_soon(): void
    {
        $u = $this->user('production');
        Task::create(['user_id' => $u->id, 'title' => 'JAUH', 'status' => 'todo', 'priority' => 'normal', 'due_date' => today()->addDays(30)->toDateString()]);

        $this->actingAs($u)->get(route('dashboard'))->assertOk()->assertDontSee('Tugas Mendekati Deadline');
    }
}
