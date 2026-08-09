<?php
// tests/Feature/NaskahMejaKerjaTest.php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Layar 1 — Meja Kerja Saya. Yang diuji di sini adalah janji utamanya: begitu dibuka,
 * pengguna langsung tahu apa tugasnya, mana yang mendesak, dan apa aksinya. Berarti
 * URUTAN dan ANGKA statistiknya harus benar, bukan sekadar halamannya terbuka.
 */
class NaskahMejaKerjaTest extends TestCase
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

    private function naskah(string $judul, array $attrs = []): TitleProgress
    {
        $title  = Title::create(['title' => $judul, 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create(['user_id' => $this->user('marketing')->id]);
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => $judul, 'title_id' => $title->id,
        ]);

        return TitleProgress::create(array_merge([
            'order_detail_id' => $detail->id,
            'status'          => 'editing',
            'assigned_role'   => 'production',
            'bidang'          => 'artikel',
            'started_at'      => now(),
        ], $attrs, ['order_detail_id' => $detail->id]));
    }

    /** @test */
    public function urutan_tugas_terlambat_dulu_lalu_deadline_terdekat_lalu_prioritas(): void
    {
        $admin = $this->user('admin', 'artikel');

        // Sengaja dibuat dengan urutan terbalik dari yang diharapkan.
        $this->naskah('TANPA DEADLINE',  ['pj_user_id' => $admin->id, 'priority' => 'low']);
        $this->naskah('PRIORITAS TINGGI', ['pj_user_id' => $admin->id, 'priority' => 'high']);
        $this->naskah('DEADLINE DEKAT',   ['pj_user_id' => $admin->id, 'target_date' => now()->addDays(3)]);
        $this->naskah('SUDAH TERLAMBAT',  ['pj_user_id' => $admin->id, 'target_date' => now()->subDays(2)]);

        $isi = $this->actingAs($admin)->get(route('naskah.workdesk'))->assertOk()->getContent();

        $urut = collect(['SUDAH TERLAMBAT', 'DEADLINE DEKAT', 'PRIORITAS TINGGI', 'TANPA DEADLINE'])
            ->map(fn (string $judul) => strpos($isi, $judul));

        $this->assertTrue(
            $urut->sliding(2)->every(fn ($pasangan) => $pasangan->first() < $pasangan->last()),
            'Urutan Meja Kerja harus: terlambat → deadline terdekat → prioritas → sisanya.'
        );
    }

    /** @test */
    public function statistik_menghitung_aktif_terlambat_dan_deadline_minggu_ini(): void
    {
        $admin = $this->user('admin', 'artikel');

        $this->naskah('A', ['pj_user_id' => $admin->id, 'target_date' => now()->subDay()]);   // terlambat
        $this->naskah('B', ['pj_user_id' => $admin->id, 'target_date' => now()->endOfWeek()]); // minggu ini
        $this->naskah('C', ['pj_user_id' => $admin->id, 'target_date' => now()->addMonth()]);  // jauh
        $this->naskah('D', []);                                                                // bukan tugasnya

        $res = $this->actingAs($admin)->get(route('naskah.workdesk'))->assertOk();

        $stat = $res->viewData('stat');
        $this->assertSame(3, $stat['aktif'], 'Hanya naskah miliknya yang dihitung.');
        $this->assertSame(1, $stat['terlambat']);
        // Yang lewat tenggat pun tetap masuk hitungan "deadline minggu ini".
        $this->assertSame(2, $stat['minggu']);
    }

    /** @test */
    public function naskah_final_dan_dibatalkan_tidak_muncul_sebagai_tugas(): void
    {
        $admin = $this->user('admin', 'artikel');

        $this->naskah('MASIH JALAN',  ['pj_user_id' => $admin->id]);
        $this->naskah('SUDAH TERBIT', ['pj_user_id' => $admin->id, 'status' => 'publish', 'archived_at' => now()]);
        $this->naskah('DIBATALKAN',   ['pj_user_id' => $admin->id, 'cancelled_at' => now(), 'cancel_reason' => 'klien batal']);

        $this->actingAs($admin)->get(route('naskah.workdesk'))
            ->assertOk()
            ->assertSee('MASIH JALAN')
            ->assertDontSee('SUDAH TERBIT')
            ->assertDontSee('DIBATALKAN');
    }

    /** @test */
    public function tugas_orang_lain_tidak_bocor_ke_meja_kerja_saya(): void
    {
        $admin = $this->user('admin', 'artikel');
        $this->naskah('MILIK SAYA', ['pj_user_id' => $admin->id]);
        $this->naskah('MILIK ADMIN LAIN', ['pj_user_id' => $this->user('admin', 'artikel')->id]);

        $this->actingAs($admin)->get(route('naskah.workdesk'))
            ->assertOk()
            ->assertSee('MILIK SAYA')
            ->assertDontSee('MILIK ADMIN LAIN');
    }

    /** @test */
    public function produksi_melihat_antrian_dan_bisa_langsung_mengambil_tugas(): void
    {
        $me = $this->user('production');
        $p  = $this->naskah('SIAP DIAMBIL', ['status' => 'menunggu_proses', 'assigned_role' => 'marketing']);

        $this->actingAs($me)->get(route('naskah.workdesk'))
            ->assertOk()
            ->assertSee('Antrian Belum Ditugaskan')
            ->assertSee('Ambil Tugas Ini');

        $this->actingAs($me)
            ->post(route('naskah.claim', $p->order_detail_id))
            ->assertRedirect();

        $p->refresh();
        $this->assertSame($me->id, $p->pelaksana_user_id);
        $this->assertSame('pembuatan', $p->status);
    }

    /** @test */
    public function naskah_yang_sudah_berpelaksana_tidak_muncul_di_antrian(): void
    {
        $me = $this->user('production');
        $this->naskah('SUDAH DIAMBIL ORANG', [
            'status' => 'pembuatan',
            'pelaksana_user_id' => $this->user('production')->id,
        ]);

        $this->actingAs($me)->get(route('naskah.workdesk'))
            ->assertOk()
            ->assertDontSee('SUDAH DIAMBIL ORANG');
    }

    /** @test */
    public function pelaksana_melihat_tombol_upload_bukan_tombol_selesaikan_tahap(): void
    {
        $me = $this->user('production');
        $this->naskah('TUGAS MENULIS', [
            'status' => 'pembuatan',
            'pelaksana_user_id' => $me->id,
            'sla_due_at' => now()->addDays(5),
        ]);

        $this->actingAs($me)->get(route('naskah.workdesk'))
            ->assertOk()
            ->assertSee('Upload Naskah')
            ->assertDontSee('Selesaikan Tahap');
    }

    /** @test */
    public function baris_terlambat_memakai_bahasa_manusia_bukan_jargon(): void
    {
        $me = $this->user('production');
        $this->naskah('LEWAT SLA', [
            'status' => 'pembuatan',
            'pelaksana_user_id' => $me->id,
            'sla_due_at' => now()->subDays(2),
            'started_at' => now()->subDays(9),
        ]);

        $isi = $this->actingAs($me)->get(route('naskah.workdesk'))->assertOk()->getContent();

        $this->assertStringContainsString('Hari ke-9 dari SLA 7 hari', $isi);
        $this->assertStringNotContainsStringIgnoringCase('aging', $isi);
    }
}
