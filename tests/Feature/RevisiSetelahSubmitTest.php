<?php

namespace Tests\Feature;

use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\TitleProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tahap `revisi` pindah ke belakang `submit`.
 *
 * Sampai 2026-08-22 urutannya terbalik: setiap artikel "melewati" revisi dalam
 * perjalanan normalnya, padahal reviewer baru bisa meminta revisi setelah naskahnya
 * masuk. Berkas ini mengunci urutan barunya beserta jalan mundurnya.
 */
class RevisiSetelahSubmitTest extends TestCase
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

    private function naskah(string $status, string $type = 'at_mandiri'): TitleProgress
    {
        $title = Title::create([
            'title'       => 'Naskah ' . fake()->unique()->words(3, true),
            'jenis'       => str_starts_with($type, 'bk_') ? 'buku' : 'artikel',
            'tipe_naskah' => 'mandiri',
            'status'      => 'disetujui',
            // Gerbang tahap akhir menuntut alamat terbit. Yang diuji di sini adalah
            // urutan tahap, bukan gerbangnya — itu urusan LinkTerbitGateTest.
            'link_terbit' => 'https://uji.test/' . fake()->unique()->slug(),
        ]);

        $detail = OrderDetail::factory()->create([
            'type' => $type, 'title' => $title->title, 'title_id' => $title->id,
        ]);

        return TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => $status,
            'assigned_role'   => TitleProgress::getHandlerForStatus($status),
            'started_at'      => now(),
        ]);
    }

    /** @return Collection<int,TitleProgress> */
    private function grupArtikel(string $status, int $jumlah): Collection
    {
        $title = Title::create([
            'title' => 'Judul Grup Revisi', 'jenis' => 'artikel',
            'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui',
            'link_terbit' => 'https://uji.test/grup',
        ]);

        $ids = [];
        for ($i = 0; $i < $jumlah; $i++) {
            $detail = OrderDetail::factory()->create([
                'type' => 'at_mandiri', 'title' => $title->title, 'title_id' => $title->id,
            ]);
            $ids[] = TitleProgress::create([
                'order_detail_id' => $detail->id,
                'status'          => $status,
                'assigned_role'   => TitleProgress::getHandlerForStatus($status),
                'started_at'      => now(),
            ])->id;
        }

        return TitleProgress::with('orderDetail')->whereIn('id', $ids)->get();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    // ─── urutan tahap ───

    /** @test */
    public function urutan_tahap_artikel_menaruh_revisi_sesudah_submit(): void
    {
        $urut = TitleProgress::ARTICLE_STAGES;

        $this->assertLessThan(
            array_search('revisi', $urut, true),
            array_search('submit', $urut, true),
            'Reviewer meminta revisi SESUDAH naskah disubmit, bukan sebelumnya.'
        );
        $this->assertLessThan(
            array_search('loa', $urut, true),
            array_search('revisi', $urut, true),
            'Revisi datang sebelum LoA.'
        );
    }

    /** @test */
    public function editing_kini_maju_ke_submit_bukan_revisi(): void
    {
        $p = $this->naskah('editing');

        app(TitleProgressService::class)->advance($p, $this->admin());

        $this->assertSame('submit', $p->fresh()->status);
    }

    /** @test */
    public function submit_maju_ke_revisi_dan_revisi_maju_ke_loa(): void
    {
        $svc = app(TitleProgressService::class);
        $p   = $this->naskah('submit');

        $svc->advance($p, $this->admin());
        $this->assertSame('revisi', $p->fresh()->status);

        $svc->advance($p->fresh(), $this->admin());
        $this->assertSame('loa', $p->fresh()->status);
    }

    /**
     * ZONA di papan Pelacakan dulu menyalin urutan tahap dengan tangan, jadi
     * memindahkan `revisi` di ARTICLE_STAGES meninggalkan papan menampilkan urutan
     * lama. Tes ini mengunci keduanya sejalan tanpa peduli isi urutannya — jadi ia
     * tetap berlaku kalau urutannya berubah lagi kelak.
     *
     * Diperiksa lewat data view, bukan posisi teks di HTML: kata "Submit" muncul juga
     * di tempat lain di halaman, dan tes yang mengandalkan itu akan lulus atau gagal
     * karena sebab yang salah.
     *
     * @test
     */
    public function kolom_papan_pelacakan_mengikuti_urutan_tahap(): void
    {
        $zona = $this->actingAs($this->admin())
            ->get(route('naskah.pelacakan', ['tipe' => 'artikel']))
            ->assertOk()
            ->viewData('zona');

        $kolom = collect($zona)->pluck('kolom')->flatten()->values()->all();

        $this->assertSame(
            array_values(array_intersect(TitleProgress::ARTICLE_STAGES, $kolom)),
            $kolom,
            'Kolom papan harus mengikuti ARTICLE_STAGES, bukan salinan yang ditulis tangan.'
        );
    }

    /**
     * Migrasi backfill diuji lewat logikanya, bukan dengan mengandalkan jalannya
     * migrasi: RefreshDatabase sudah menjalankan semuanya sebelum tes dimulai, jadi
     * datanya belum ada saat migrasi lewat. Yang dikunci di sini adalah aturannya.
     *
     * @test
     */
    public function aturan_backfill_membedakan_yang_pernah_submit(): void
    {
        $sudah = $this->naskah('revisi');
        $belum = $this->naskah('revisi');

        DB::table('tb_title_progress_logs')->insert([
            'title_progress_id' => $sudah->id,
            'event'             => 'status_advanced',
            'from_value'        => 'editing',
            'to_value'          => 'submit',
            'is_correction'     => 0,
            'created_at'        => now(),
        ]);

        $migrasi = include database_path('migrations/2026_08_22_000003_pindahkan_tahap_revisi_lama.php');
        $migrasi->up();

        $this->assertSame('revisi', $sudah->fresh()->status,
            'Sudah pernah submit — posisinya di urutan baru sudah benar.');
        $this->assertSame('editing', $belum->fresh()->status,
            'Belum pernah submit — di urutan baru `revisi` berarti sudah submit, jadi harus mundur.');

        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $belum->id,
            'from_value'        => 'revisi',
            'to_value'          => 'editing',
            'is_correction'     => 1,
        ]);
    }

    // ─── jalan mundur ───

    /**
     * applyGroup() punya penjaga `$idx > $targetIdx → continue` yang dimaksudkan untuk
     * "jangan tarik mundur anggota grup yang sudah lebih maju". Untuk perpindahan mundur
     * SETIAP anggota memenuhi syarat itu, jadi tanpa penanganan khusus fungsinya
     * mengembalikan 0 dan tak menggerakkan apa pun — sambil terlihat berhasil.
     *
     * Assertion-nya sengaja pada status tiap baris, bukan pada nilai kembalian.
     *
     * @test
     */
    public function mundur_dari_loa_benar_benar_memindahkan_seluruh_grup(): void
    {
        $anggota = $this->grupArtikel('loa', 3);

        app(TitleProgressService::class)
            ->kembalikan($anggota->first(), 'revisi', $this->admin(), 'Reviewer minta revisi minor');

        foreach ($anggota as $p) {
            $this->assertSame('revisi', $p->fresh()->status,
                'Seluruh order sejudul harus ikut mundur, bukan hanya yang ditekan.');
        }
    }

    /** @test */
    public function mundur_tercatat_sebagai_alur_normal_bukan_koreksi(): void
    {
        $p = $this->naskah('loa');

        app(TitleProgressService::class)
            ->kembalikan($p, 'revisi', $this->admin(), 'Reviewer minta revisi');

        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id,
            'from_value'        => 'loa',
            'to_value'          => 'revisi',
            'is_correction'     => 0,
        ]);
    }

    /** @test */
    public function maju_tetap_menolak_menarik_mundur_anggota_yang_lebih_depan(): void
    {
        $anggota = $this->grupArtikel('editing', 2);
        $depan   = $anggota->last();
        $depan->update(['status' => 'loa']);

        app(TitleProgressService::class)->advance($anggota->first(), $this->admin());

        $this->assertSame('loa', $depan->fresh()->status,
            'Penjaga lama harus tetap berlaku untuk perpindahan maju.');
    }

    /** @test */
    public function buku_dikembalikan_ke_pembuatan_bukan_melompat_ke_layout(): void
    {
        $p = $this->naskah('editing', 'bk_mandiri');

        app(TitleProgressService::class)
            ->kembalikan($p, 'pembuatan', $this->admin(), 'Sitasi bab 2 belum lengkap');

        $this->assertSame('pembuatan', $p->fresh()->status);
    }

    /** @test */
    public function mundur_ditolak_bila_naskah_sudah_diarsipkan(): void
    {
        $p = $this->naskah('loa');
        $p->update(['archived_at' => now()]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(TitleProgressService::class)
            ->kembalikan($p, 'revisi', $this->admin(), 'coba tarik mundur');
    }

    /** @test */
    public function mundur_menolak_pasangan_tahap_yang_tidak_dibolehkan(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(TitleProgressService::class)
            ->kembalikan($this->naskah('loa'), 'editing', $this->admin(), 'lompat jauh');
    }

    /** @test */
    public function mundur_wajib_beralasan(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(TitleProgressService::class)
            ->kembalikan($this->naskah('loa'), 'revisi', $this->admin(), '   ');
    }

    /** @test */
    public function urutan_tahap_buku_tidak_ikut_berubah(): void
    {
        $this->assertSame(
            ['menunggu_proses', 'pembuatan', 'editing', 'layout',
             'proofreading', 'isbn', 'cetak', 'terbit'],
            TitleProgress::BOOK_STAGES,
            'Buku tak punya tahap revisi jurnal — urutannya tak boleh tersenggol.'
        );
    }
}
