<?php

namespace Tests\Feature;

use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NaskahInfoPublikasiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->seed(\Database\Seeders\AccessMatrixSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u->fresh();
    }

    /** @return array{0: Title, 1: TitleProgress} */
    private function naskah(): array
    {
        $title  = Title::create(['title' => 'Artikel Info', 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => $title->title, 'title_id' => $title->id,
        ]);
        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'loa',
            'bidang' => 'artikel', 'started_at' => now(),
        ]);

        return [$title, $progress];
    }

    /** @test */
    public function admin_menyimpan_link_terbit_lewat_update_info(): void
    {
        [$title] = $this->naskah();

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/artikel-info',
            ])->assertRedirect();

        $this->assertSame('https://jurnal.test/artikel-info', $title->fresh()->link_terbit);
    }

    /**
     * Formnya dipakai dari DUA layar. Tanpa redirect sadar-asal, menyimpan dari layar
     * naskah melempar orang ke halaman judul dan konteks kerjanya hilang.
     *
     * @test
     */
    public function menyimpan_dari_layar_naskah_kembali_ke_layar_naskah(): void
    {
        [$title, $progress] = $this->naskah();
        $kembali = route('naskah.show', $progress->order_detail_id);

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/x',
                '_redirect'   => $kembali,
            ])->assertRedirect($kembali);
    }

    /**
     * Redirect hanya boleh ke dalam aplikasi sendiri.
     *
     * @test
     */
    public function redirect_ke_luar_aplikasi_diabaikan(): void
    {
        [$title] = $this->naskah();

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/x',
                '_redirect'   => 'https://situs-jahat.test/panen',
            ])->assertRedirect(route('title.show', $title->id));
    }

    /**
     * url('/') pulang TANPA garis miring penutup, jadi pencocokan awalan telanjang
     * meloloskan host yang cuma berawalan sama — domain yang bisa didaftarkan siapa saja.
     *
     * @test
     */
    public function redirect_ke_host_yang_cuma_berawalan_sama_ditolak(): void
    {
        [$title] = $this->naskah();

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/x',
                '_redirect'   => rtrim(url('/'), '/') . '.situs-jahat.test/panen',
            ])->assertRedirect(route('title.show', $title->id));
    }

    /** @test */
    public function link_dicerminkan_ke_direktori_jurnal_bila_barisnya_ada(): void
    {
        [$title] = $this->naskah();
        $journal = Journal::create(['nama' => 'Jurnal Uji']);
        $sub     = JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $title->id]);

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/cermin',
            ])->assertRedirect();

        $this->assertSame('https://jurnal.test/cermin', $sub->fresh()->link_publish);
    }

    /**
     * Tanpa baris submission, penyimpanan tetap berhasil — direktori menyusul.
     *
     * @test
     */
    public function tanpa_baris_submission_tetap_tersimpan(): void
    {
        [$title] = $this->naskah();

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/tanpa-submission',
            ])->assertRedirect();

        $this->assertSame('https://jurnal.test/tanpa-submission', $title->fresh()->link_terbit);
        $this->assertSame(0, JournalSubmission::count());
    }

    /** @test */
    public function halaman_judul_juga_punya_field_link_terbit(): void
    {
        [$title] = $this->naskah();

        $this->actingAs($this->user('admin'))->get(route('title.show', $title->id))
            ->assertOk()
            ->assertSee('Link Artikel Terbit');
    }

    /** Judul lengkap berisi setiap field publikasi + dua opsi jurnal. */
    private function judulLengkap(): Title
    {
        $title = Title::create([
            'title' => 'Artikel Lengkap', 'jenis' => 'artikel',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
            'code' => 'KODE-LAMA', 'target_terbit' => '2026-12-01',
            'jurnal_target' => 'Jurnal Lama', 'jurnal_link' => 'https://lama.test',
            'template_link' => 'https://template.test', 'apc_info' => 'Rp 1.000.000',
            'catatan_publikasi' => 'Catatan lama',
        ]);
        $title->journalOptions()->create(['nama_jurnal' => 'Opsi A', 'urutan' => 0]);
        $title->journalOptions()->create(['nama_jurnal' => 'Opsi B', 'urutan' => 1]);

        return $title->fresh();
    }

    /**
     * Kiriman sebagian tidak boleh menghapus apa pun yang tak disebutnya.
     *
     * Dulu setiap field dibaca `?? null`, jadi kunci yang absen berarti "kosongkan" —
     * aman selama satu-satunya pengirim adalah formulir judul yang mengirim semuanya,
     * tapi layar naskah hanya mengirim sebagian.
     *
     * @test
     */
    public function kiriman_sebagian_tidak_menghapus_field_lain(): void
    {
        $title = $this->judulLengkap();

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/sebagian',
            ])->assertRedirect();

        $segar = $title->fresh();
        $this->assertSame('https://jurnal.test/sebagian', $segar->link_terbit);
        $this->assertSame('KODE-LAMA', $segar->code, 'kode tak boleh dibuat ulang saat tak dikirim');
        $this->assertSame('2026-12-01', $segar->target_terbit->toDateString());
        $this->assertSame('Jurnal Lama', $segar->jurnal_target);
        $this->assertSame('https://lama.test', $segar->jurnal_link);
        $this->assertSame('https://template.test', $segar->template_link);
        $this->assertSame('Rp 1.000.000', $segar->apc_info);
        $this->assertSame('Catatan lama', $segar->catatan_publikasi);
    }

    /**
     * Opsi jurnal hanya boleh diganti oleh formulir yang memang menampilkannya.
     *
     * @test
     */
    public function kiriman_sebagian_tidak_menghapus_opsi_jurnal(): void
    {
        $title = $this->judulLengkap();

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/sebagian',
            ])->assertRedirect();

        $this->assertSame(2, $title->fresh()->journalOptions()->count());
    }

    /** @test */
    public function formulir_lengkap_tetap_mengganti_opsi_jurnal(): void
    {
        $title = $this->judulLengkap();

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'code' => 'KODE-LAMA',
                'journal_options_dikirim' => 1,
                'journal_options' => [['nama_jurnal' => 'Opsi Baru']],
            ])->assertRedirect();

        $opsi = $title->fresh()->journalOptions()->get();
        $this->assertCount(1, $opsi);
        $this->assertSame('Opsi Baru', $opsi->first()->nama_jurnal);
    }

    /**
     * Inilah kasus yang penandanya ada untuk membedakan: menghapus semua opsi lalu
     * menyimpan tiba TANPA kunci `journal_options` sama sekali — sama persis dengan
     * kiriman sebagian, kalau tak ada penanda.
     *
     * @test
     */
    public function formulir_lengkap_masih_bisa_mengosongkan_opsi_jurnal(): void
    {
        $title = $this->judulLengkap();

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'code' => 'KODE-LAMA',
                'journal_options_dikirim' => 1,
            ])->assertRedirect();

        $this->assertSame(0, $title->fresh()->journalOptions()->count());
    }

    /** Dikirim tapi kosong tetap berarti "kosongkan" — bukan "abaikan". */
    /** @test */
    public function field_yang_dikirim_kosong_tetap_dikosongkan(): void
    {
        $title = $this->judulLengkap();

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'jurnal_target' => '',
            ])->assertRedirect();

        $this->assertNull($title->fresh()->jurnal_target);
    }
}
