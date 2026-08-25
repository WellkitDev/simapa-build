<?php
// tests/Feature/UtasTugasTest.php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Notifications\DatabaseNotification;
use App\Services\TaskThreadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Utas aktivitas per tugas.
 *
 * Sebelum ini sebuah tugas cuma kotak centang pribadi: pemberi tugas tak punya cara
 * tahu apa pun selain `todo/in_progress/done`, dan pelaksananya tak punya tempat
 * bercerita. Satu-satunya jalan mengabari kemajuan adalah menemui orangnya.
 *
 * Yang diuji di sini bukan "ada tabel baru", melainkan tiga hal yang membuat utasnya
 * berguna: siapa boleh menulis, siapa yang dikabari, dan apakah utasnya benar-benar
 * tak bisa disunting.
 */
class UtasTugasTest extends TestCase
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

    /** Tugas dari $pemberi untuk $pelaksana. */
    private function tugas(User $pelaksana, User $pemberi): Task
    {
        return Task::create([
            'user_id' => $pelaksana->id, 'created_by' => $pemberi->id,
            'title' => 'Rekap penjualan Agustus', 'status' => 'todo', 'priority' => 'normal',
        ]);
    }

    // ─── siapa boleh menulis ───

    /** @test */
    public function pelaksana_bisa_melaporkan_kemajuan_di_tugasnya(): void
    {
        $pelaksana = $this->user('production');
        $pemberi   = $this->user('marketing');
        $tugas     = $this->tugas($pelaksana, $pemberi);

        $this->actingAs($pelaksana)
            ->post(route('task.report', $tugas->id), [
                'body' => 'Data Agustus sudah ditarik, tinggal cek selisih kas kecil.',
                'progress' => 60,
            ])->assertRedirect();

        $entri = $tugas->updates()->where('kind', TaskUpdate::LAPORAN)->first();

        $this->assertNotNull($entri, 'Laporan pelaksana harus tercatat di utas.');
        $this->assertSame($pelaksana->id, $entri->user_id);
        $this->assertSame(60, $entri->progress);
    }

    /**
     * Kemajuan yang dilaporkan naik ke tugasnya, supaya papan bisa menampilkan bilahnya
     * tanpa membaca seluruh utas tiap kartu.
     *
     * @test
     */
    public function kemajuan_yang_dilaporkan_ikut_tersimpan_di_tugasnya(): void
    {
        $pelaksana = $this->user('production');
        $tugas     = $this->tugas($pelaksana, $this->user('marketing'));

        app(TaskThreadService::class)->laporkan($tugas, $pelaksana, 'Setengah jalan.', 50);

        $this->assertSame(50, $tugas->fresh()->progress);
    }

    /**
     * Laporan berikutnya yang tak menyebut angka TIDAK mengembalikan kemajuan ke nol.
     * Diam bukan berarti mundur.
     *
     * @test
     */
    public function laporan_tanpa_angka_tidak_menurunkan_kemajuan(): void
    {
        $pelaksana = $this->user('production');
        $tugas     = $this->tugas($pelaksana, $this->user('marketing'));
        $svc       = app(TaskThreadService::class);

        $svc->laporkan($tugas, $pelaksana, 'Setengah jalan.', 50);
        $svc->laporkan($tugas->fresh(), $pelaksana, 'Masih menunggu data dari keuangan.');

        $this->assertSame(50, $tugas->fresh()->progress);
    }

    /** @test */
    public function pemberi_tugas_juga_bisa_menulis_di_utas(): void
    {
        $pelaksana = $this->user('production');
        $pemberi   = $this->user('marketing');
        $tugas     = $this->tugas($pelaksana, $pemberi);

        $this->actingAs($pemberi)
            ->post(route('task.report', $tugas->id), ['body' => 'Tolong dahulukan cabang Medan.'])
            ->assertRedirect();

        $this->assertSame(1, $tugas->updates()->where('kind', TaskUpdate::LAPORAN)->count());
    }

    /** @test */
    public function orang_luar_tidak_bisa_menulis_di_utas_tugas_orang_lain(): void
    {
        $tugas = $this->tugas($this->user('production'), $this->user('marketing'));

        $this->actingAs($this->user('admin'))
            ->post(route('task.report', $tugas->id), ['body' => 'Numpang lewat.'])
            ->assertForbidden();

        $this->assertSame(0, $tugas->updates()->count());
    }

    /** @test */
    public function laporan_kosong_ditolak(): void
    {
        $pelaksana = $this->user('production');
        $tugas     = $this->tugas($pelaksana, $this->user('marketing'));

        $this->actingAs($pelaksana)
            ->post(route('task.report', $tugas->id), ['body' => '   '])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, $tugas->updates()->count());
    }

    // ─── siapa yang dikabari ───

    /** @test */
    public function laporan_pelaksana_mengabari_pemberi_tugasnya(): void
    {
        Notification::fake();

        $pelaksana = $this->user('production');
        $pemberi   = $this->user('marketing');
        $tugas     = $this->tugas($pelaksana, $pemberi);

        app(TaskThreadService::class)->laporkan($tugas, $pelaksana, 'Sudah 60 persen.', 60);

        Notification::assertSentTo($pemberi, DatabaseNotification::class);
        Notification::assertNotSentTo($pelaksana, DatabaseNotification::class);
    }

    /** @test */
    public function catatan_pemberi_tugas_mengabari_pelaksananya(): void
    {
        Notification::fake();

        $pelaksana = $this->user('production');
        $pemberi   = $this->user('marketing');
        $tugas     = $this->tugas($pelaksana, $pemberi);

        app(TaskThreadService::class)->laporkan($tugas, $pemberi, 'Dahulukan cabang Medan.');

        Notification::assertSentTo($pelaksana, DatabaseNotification::class);
        Notification::assertNotSentTo($pemberi, DatabaseNotification::class);
    }

    /**
     * Tugas untuk diri sendiri tak boleh mengirim notifikasi ke mana pun — memberi tahu
     * orang tentang tulisannya sendiri mengajari orang mengabaikan sisanya.
     *
     * @test
     */
    public function tugas_untuk_diri_sendiri_tidak_mengirim_notifikasi(): void
    {
        Notification::fake();

        $aku   = $this->user('production');
        $tugas = $this->tugas($aku, $aku);

        app(TaskThreadService::class)->laporkan($tugas, $aku, 'Catatan pribadi.');

        Notification::assertNothingSent();
    }

    // ─── peristiwa sistem ───

    /** @test */
    public function perubahan_status_tercatat_di_utas_tanpa_diminta(): void
    {
        $pelaksana = $this->user('production');
        $tugas     = $this->tugas($pelaksana, $this->user('marketing'));

        $this->actingAs($pelaksana)
            ->patch(route('task.status', $tugas->id), ['status' => 'in_progress'])
            ->assertOk();

        $sistem = $tugas->updates()->where('kind', TaskUpdate::SISTEM)->get();

        $this->assertGreaterThanOrEqual(1, $sistem->count(),
            'Perpindahan status harus meninggalkan jejak; tanpa itu utasnya bohong soal apa yang terjadi.');
        $this->assertStringContainsString('Dikerjakan', $sistem->last()->body);
    }

    /** @test */
    public function pengalihan_ke_orang_lain_tercatat_di_utas(): void
    {
        $lama    = $this->user('production');
        $baru    = $this->user('admin');
        $pemberi = $this->user('marketing');
        $tugas   = $this->tugas($lama, $pemberi);

        $this->actingAs($pemberi)
            ->put(route('task.update', $tugas->id), [
                'title' => $tugas->title, 'priority' => 'normal', 'assignee' => $baru->id,
            ])->assertRedirect();

        $jejak = $tugas->updates()->where('kind', TaskUpdate::SISTEM)->get()
            ->filter(fn ($e) => str_contains($e->body, 'Dialihkan'));

        $this->assertCount(1, $jejak, 'Pengalihan tugas harus terbaca di utasnya.');
        $this->assertSame($baru->id, $tugas->fresh()->user_id);
    }

    // ─── utasnya tak bisa disunting ───

    /** @test */
    public function entri_utas_tidak_punya_updated_at(): void
    {
        $pelaksana = $this->user('production');
        $tugas     = $this->tugas($pelaksana, $this->user('marketing'));

        $entri = app(TaskThreadService::class)->laporkan($tugas, $pelaksana, 'Catatan.');

        $this->assertNull(TaskUpdate::UPDATED_AT,
            'Laporan adalah catatan apa yang terjadi; riwayat yang bisa disunting adalah akuntabilitas yang mati.');
        $this->assertNotNull($entri->created_at);
    }

    /** @test */
    public function menghapus_tugas_ikut_membawa_utasnya(): void
    {
        $pelaksana = $this->user('production');
        $tugas     = $this->tugas($pelaksana, $pelaksana);
        app(TaskThreadService::class)->laporkan($tugas, $pelaksana, 'Catatan.');

        $this->assertSame(1, TaskUpdate::count());

        $this->actingAs($pelaksana)->delete(route('task.destroy', $tugas->id))->assertRedirect();

        $this->assertSame(0, TaskUpdate::count(), 'Utas yatim tak punya arti dan tak bisa dibaca siapa pun.');
    }

    // ─── halaman detail ───

    /** @test */
    public function halaman_detail_menampilkan_utas_dan_formulir_laporan(): void
    {
        $pelaksana = $this->user('production');
        $pemberi   = $this->user('marketing');
        $tugas     = $this->tugas($pelaksana, $pemberi);
        app(TaskThreadService::class)->laporkan($tugas, $pelaksana, 'Sudah ditarik datanya.', 40);

        $this->actingAs($pemberi)->get(route('task.show', $tugas->id))
            ->assertOk()
            ->assertSee('Rekap penjualan Agustus')
            ->assertSee('Sudah ditarik datanya.')
            ->assertSee('Tulis laporan');
    }

    /**
     * Halaman detail yang tak ditautkan dari mana pun sama saja dengan tak ada. Papan
     * dan daftar keduanya harus jadi pintunya.
     *
     * @test
     */
    public function papan_dan_daftar_menautkan_ke_halaman_detail(): void
    {
        $pelaksana = $this->user('production');
        $tugas     = $this->tugas($pelaksana, $pelaksana);
        $tautan    = route('task.show', $tugas->id);

        foreach (['task.board', 'task.index'] as $layar) {
            $this->actingAs($pelaksana)->get(route($layar))
                ->assertOk()
                ->assertSee($tautan, escape: false);
        }
    }

    /**
     * Kartu papan menampilkan bilah kemajuan yang DILAPORKAN, bukan tebakan dari status.
     *
     * @test
     */
    public function kartu_papan_menampilkan_kemajuan_dan_jumlah_laporan(): void
    {
        $pelaksana = $this->user('production');
        $tugas     = $this->tugas($pelaksana, $this->user('marketing'));
        app(TaskThreadService::class)->laporkan($tugas, $pelaksana, 'Sudah separuh.', 45);

        $this->actingAs($pelaksana)->get(route('task.board'))
            ->assertOk()
            ->assertSee('width:45%', escape: false)
            ->assertSee('1 laporan');
    }

    /** @test */
    public function orang_luar_tidak_bisa_membuka_detail_tugas_orang_lain(): void
    {
        $tugas = $this->tugas($this->user('production'), $this->user('marketing'));

        $this->actingAs($this->user('admin'))
            ->get(route('task.show', $tugas->id))
            ->assertForbidden();
    }

    /** @test */
    public function manager_boleh_membaca_utas_siapa_pun(): void
    {
        $tugas = $this->tugas($this->user('production'), $this->user('marketing'));

        $this->actingAs($this->user('manager'))
            ->get(route('task.show', $tugas->id))
            ->assertOk();
    }
}
