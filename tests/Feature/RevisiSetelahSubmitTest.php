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
