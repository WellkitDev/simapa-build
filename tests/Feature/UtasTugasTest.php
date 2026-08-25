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

    // ─── penerima tugas hanya boleh mengerjakan, bukan mengubah syaratnya ───

    /**
     * Pelaksana tak boleh menyunting tugas yang diberikan kepadanya.
     *
     * Judul, deskripsi, prioritas, tenggat, dan penerima adalah SYARAT tugas — milik
     * yang memberi. Membiarkan penerimanya mengubah syaratnya sendiri membuat penugasan
     * kehilangan artinya: siapa pun bisa menurunkan prioritas atau menggeser tenggat
     * pekerjaannya sendiri tanpa siapa pun tahu.
     *
     * @test
     */
    public function pelaksana_tidak_bisa_menyunting_syarat_tugasnya(): void
    {
        $pelaksana = $this->user('production');
        $pemberi   = $this->user('marketing');
        $tugas     = $this->tugas($pelaksana, $pemberi);

        $this->actingAs($pelaksana)
            ->put(route('task.update', $tugas->id), [
                'title' => 'Judul diubah sendiri', 'priority' => 'low',
            ])->assertForbidden();

        $this->actingAs($pelaksana)
            ->patch(route('task.schedule', $tugas->id), ['due_date' => today()->addDays(30)->toDateString()])
            ->assertForbidden();

        $this->actingAs($pelaksana)
            ->delete(route('task.destroy', $tugas->id))
            ->assertForbidden();

        $this->assertSame('Rekap penjualan Agustus', $tugas->fresh()->title);
    }

    /**
     * Yang BOLEH dilakukan pelaksana: menggerakkan statusnya dan melapor. Itu
     * pekerjaannya, bukan syarat tugasnya.
     *
     * @test
     */
    public function pelaksana_tetap_bisa_menggerakkan_status_dan_melapor(): void
    {
        $pelaksana = $this->user('production');
        $tugas     = $this->tugas($pelaksana, $this->user('marketing'));

        $this->actingAs($pelaksana)
            ->patch(route('task.status', $tugas->id), ['status' => 'in_progress'])
            ->assertOk();

        $this->actingAs($pelaksana)
            ->post(route('task.report', $tugas->id), ['body' => 'Mulai dikerjakan.', 'progress' => 20])
            ->assertRedirect();

        $tugas->refresh();
        $this->assertSame('in_progress', $tugas->status);
        $this->assertSame(20, $tugas->progress);
    }

    /** Pemberi tugas tetap pemilik syaratnya. */
    /** @test */
    public function pemberi_tugas_tetap_bisa_menyunting(): void
    {
        $pemberi = $this->user('marketing');
        $tugas   = $this->tugas($this->user('production'), $pemberi);

        $this->actingAs($pemberi)
            ->put(route('task.update', $tugas->id), ['title' => 'Judul direvisi', 'priority' => 'high'])
            ->assertRedirect();

        $this->assertSame('Judul direvisi', $tugas->fresh()->title);
    }

    /**
     * Tugas tanpa pembuat — data lama, sebelum `created_by` diisi — tak boleh jadi tugas
     * yang tak bisa diurus siapa pun. Di produksi tak ada satu pun akun manager, jadi
     * memerlukannya berarti mengunci tugas itu selamanya.
     *
     * @test
     */
    public function tugas_tanpa_pembuat_tetap_bisa_diurus_pelaksananya(): void
    {
        $pelaksana = $this->user('production');
        $tugas = Task::create([
            'user_id' => $pelaksana->id, 'created_by' => null,
            'title' => 'Tugas warisan', 'status' => 'todo', 'priority' => 'normal',
        ]);

        $this->actingAs($pelaksana)
            ->put(route('task.update', $tugas->id), ['title' => 'Tugas warisan (diperbarui)', 'priority' => 'normal'])
            ->assertRedirect();

        $this->assertSame('Tugas warisan (diperbarui)', $tugas->fresh()->title);
    }

    /**
     * Layar ikut jujur: tombol Edit dan Hapus tak boleh dipajang kepada orang yang akan
     * ditolak servernya. Gerbang yang hanya ada di server menghasilkan tombol yang
     * memberi harapan lalu memunculkan halaman 403.
     *
     * @test
     */
    public function papan_tidak_memajang_tombol_sunting_untuk_pelaksana(): void
    {
        $pelaksana = $this->user('production');
        $tugas     = $this->tugas($pelaksana, $this->user('marketing'));

        // Papan bawaan menampilkan tugas MILIK yang membukanya; untuk melihat tugas ini
        // dari sisi pemberinya, papannya harus dibuka atas nama pelaksananya.
        $lihat = fn ($aktor) => substr_count(
            $this->actingAs($aktor)
                ->get(route('task.board', ['user_id' => $tugas->user_id]))
                ->assertOk()->getContent(),
            'data-edit-task'
        );

        // Halamannya SELALU memuat satu `data-edit-task` di pemilih JS-nya. Yang dihitung
        // di sini tombolnya, jadi patokannya kemunculan KEDUA — bukan ada-tidaknya teks.
        $this->assertSame(1, $lihat($pelaksana),
            'Tombol Edit dipajang ke pelaksana yang justru akan ditolak servernya.');

        $pemberi = $tugas->creator;
        $this->assertGreaterThan(1, $lihat($pemberi),
            'Prasyarat: pemberi tugas memang harus melihat tombol Edit-nya.');
    }

    /**
     * Pengguna tadi tak menemukan tempat menulis laporan sampai diberi tahu bahwa
     * judulnya harus diklik. Papan karena itu harus MENGATAKANNYA, bukan menyembunyikan
     * pintunya di balik judul yang tak terlihat seperti tautan.
     *
     * @test
     */
    public function papan_menunjukkan_jalan_ke_laporan(): void
    {
        $pelaksana = $this->user('production');
        $tugas     = $this->tugas($pelaksana, $this->user('marketing'));

        $isi = $this->actingAs($pelaksana)->get(route('task.board'))
            ->assertOk()->getContent();

        // `data-lapor` dipakai, bukan kata "Lapor": menu sidebar "Laporan Harian" akan
        // membuat asersi teks lolos tanpa satu pun tombol di kartunya.
        $this->assertStringContainsString('data-lapor', $isi,
            'Kartu papan harus punya jalan yang terlihat menuju laporan.');
        $this->assertStringContainsString(route('task.show', $tugas->id), $isi);
        $this->assertStringContainsString('menulis laporan', $isi,
            'Papan harus mengatakan di mana laporan ditulis, bukan menyembunyikannya di balik judul.');
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
