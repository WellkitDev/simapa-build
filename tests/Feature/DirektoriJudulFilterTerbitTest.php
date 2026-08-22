<?php

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
 * A8 — ditutup sebagai KEPUTUSAN, bukan kolom baru.
 *
 * `tb_titles.status` adalah status tata kelola (draft/ditolak/disetujui), bukan status
 * produksi. Pertanyaan "naskahnya sudah terbit belum" sudah terjawab lewat turunan
 * `manuscriptStatus()`, yang sudah tahu mengabaikan order yang ditarik karena refund.
 * Menambah `status = 'terbit'` berarti menyimpan turunan sebagai kolom — dan kolom
 * turunan pasti basi di sistem ini, karena satu judul punya banyak order, order bisa
 * ditarik, dan tahap bisa dikoreksi mundur oleh superadmin.
 *
 * Lencananya sudah ada di Direktori Judul. Yang benar-benar kurang: cara menyaringnya.
 */
class DirektoriJudulFilterTerbitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function judul(string $nama, ?string $tahap): Title
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        $title = Title::create([
            'title' => $nama, 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri',
            'status' => 'disetujui',
        ]);
        $order  = Order::factory()->create(['user_id' => $u->id]);
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => $nama, 'title_id' => $title->id, 'chapters' => 1,
        ]);
        if ($tahap) {
            TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => $tahap,
                'assigned_role' => TitleProgress::getHandlerForStatus($tahap),
                'bidang' => 'artikel', 'started_at' => now(),
            ]);
        }

        return $title->fresh();
    }

    private function super(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u->fresh();
    }

    /** @test */
    public function filter_sudah_terbit_hanya_menampilkan_yang_naskahnya_final(): void
    {
        $this->judul('Artikel Sudah Terbit', 'publish');
        $this->judul('Artikel Masih Editing', 'editing');

        $isi = $this->actingAs($this->super())
            ->get(route('title.index', ['terbit' => 'sudah']))->assertOk()->getContent();

        $this->assertStringContainsString('Artikel Sudah Terbit', $isi);
        $this->assertStringNotContainsString('Artikel Masih Editing', $isi);
    }

    /** @test */
    public function filter_belum_terbit_menyembunyikan_yang_sudah_final(): void
    {
        $this->judul('Artikel Sudah Terbit', 'publish');
        $this->judul('Artikel Masih Editing', 'editing');

        $isi = $this->actingAs($this->super())
            ->get(route('title.index', ['terbit' => 'belum']))->assertOk()->getContent();

        $this->assertStringContainsString('Artikel Masih Editing', $isi);
        $this->assertStringNotContainsString('Artikel Sudah Terbit', $isi);
    }

    /** @test */
    public function judul_tanpa_progress_dihitung_belum_terbit(): void
    {
        $this->judul('Artikel Tanpa Progress', null);

        $isi = $this->actingAs($this->super())
            ->get(route('title.index', ['terbit' => 'belum']))->assertOk()->getContent();

        $this->assertStringContainsString('Artikel Tanpa Progress', $isi);
    }

    /** @test */
    public function tanpa_filter_semuanya_tampil(): void
    {
        $this->judul('Artikel Sudah Terbit', 'publish');
        $this->judul('Artikel Masih Editing', 'editing');

        $isi = $this->actingAs($this->super())->get(route('title.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Artikel Sudah Terbit', $isi);
        $this->assertStringContainsString('Artikel Masih Editing', $isi);
    }
}
