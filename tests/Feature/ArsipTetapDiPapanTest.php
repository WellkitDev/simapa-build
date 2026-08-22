<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Title;
use App\Models\TitleArchive;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\Notifier;
use App\Services\TitleArchivalService;
use App\Services\TitleProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Naskah terbit bertahan di papan sampai arsipnya DISETUJUI.
 *
 * `archived_at` dulu berarti "sudah terbit", padahal namanya menjanjikan "sudah
 * diarsipkan". Naskah lenyap dari papan pada detik ia terbit — sebelum ada yang
 * mengajukan arsipnya. Di produksi 24 naskah menghilang begitu dan tb_title_archives
 * kosong: modul arsipnya tak pernah dipakai karena pintu masuknya sudah hilang.
 */
class ArsipTetapDiPapanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class)->shouldIgnoreMissing();
        $this->mock(Notifier::class)->shouldIgnoreMissing();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    /** Artikel di tahap LoA, siap dimajukan ke Publish. @return array{0: Title, 1: TitleProgress} */
    private function naskah(string $status = 'loa'): array
    {
        $title = Title::create([
            'title' => 'Artikel Arsip ' . fake()->unique()->words(2, true),
            'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
            'link_terbit' => 'https://uji.test/' . fake()->unique()->slug(),
        ]);

        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => $title->title, 'title_id' => $title->id, 'cost_amount' => 100000,
        ]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'lunas',
                         'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);

        $p = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'assigned_role' => TitleProgress::getHandlerForStatus($status),
            'started_at' => now(),
        ]);

        return [$title->fresh(), $p];
    }

    // ─── inti perubahan ───

    /** @test */
    public function naskah_terbit_tidak_langsung_terarsip(): void
    {
        [$title, $p] = $this->naskah();

        app(TitleProgressService::class)->advance($p, $this->superadmin());

        $segar = $p->fresh();
        $this->assertSame('publish', $segar->status);
        $this->assertNull($segar->archived_at,
            'Terbit bukan berarti terarsip — arsipnya belum diajukan siapa pun.');
        $this->assertSame(1, TitleProgress::active()->count(),
            'Naskah harus tetap terlihat di papan.');
    }

    /** @test */
    public function arsip_disetujui_barulah_menandai_terarsip(): void
    {
        [$title, $p] = $this->naskah();
        $sa = $this->superadmin();

        app(TitleProgressService::class)->advance($p, $sa);
        $this->assertNull($p->fresh()->archived_at);

        app(TitleArchivalService::class)->approve($title, $sa, 'lengkap');

        $this->assertNotNull($p->fresh()->archived_at);
        $this->assertSame(0, TitleProgress::active()->count());
    }

    /** @test */
    public function arsip_yang_baru_diajukan_belum_menandai_terarsip(): void
    {
        [$title, $p] = $this->naskah();
        $sa = $this->superadmin();

        app(TitleProgressService::class)->advance($p, $sa);
        TitleArchive::create(['title_id' => $title->id, 'status' => 'diajukan', 'submitted_by' => $sa->id]);

        $this->assertNull($p->fresh()->archived_at,
            'Diajukan bukan disetujui — naskahnya masih menunggu dan harus terlihat.');
    }

    /** @test */
    public function arsip_ditolak_membiarkan_naskah_di_papan(): void
    {
        [$title, $p] = $this->naskah();
        $sa = $this->superadmin();

        app(TitleProgressService::class)->advance($p, $sa);
        app(TitleArchivalService::class)->reject($title, $sa, 'berkas kurang');

        $this->assertNull($p->fresh()->archived_at);
        $this->assertSame(1, TitleProgress::active()->count(),
            'Arsip yang ditolak harus kembali jadi pekerjaan yang terlihat.');
    }

    /** @test */
    public function persetujuan_menandai_seluruh_order_sejudul(): void
    {
        $title = Title::create([
            'title' => 'Buku Banyak Order', 'jenis' => 'buku',
            'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui',
            'link_terbit' => 'https://uji.test/buku',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $detail = OrderDetail::factory()->create([
                'order_id' => Order::factory()->create()->id, 'type' => 'bk_kolab',
                'title' => $title->title, 'title_id' => $title->id, 'chapters' => $i + 1,
            ]);
            TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => 'terbit',
                'assigned_role' => 'admin', 'bidang' => 'buku', 'started_at' => now(),
            ]);
        }

        app(TitleArchivalService::class)->approve($title->fresh(), $this->superadmin(), null);

        $this->assertSame(0, TitleProgress::active()->count(),
            'Arsip milik judul — seluruh ordernya ikut pindah.');
    }

    /** @test */
    public function koreksi_mundur_mengembalikan_naskah_dari_arsip(): void
    {
        [$title, $p] = $this->naskah();
        $sa = $this->superadmin();

        app(TitleProgressService::class)->advance($p, $sa);
        app(TitleArchivalService::class)->approve($title, $sa, null);
        $this->assertNotNull($p->fresh()->archived_at);

        app(TitleProgressService::class)->correct($p->fresh(), 'loa', $sa, 'ada yang keliru');

        $this->assertNull($p->fresh()->archived_at,
            'Koreksi mundur berhak menarik naskah kembali ke papan.');
    }

    // ─── papan ───

    /** @test */
    public function kartu_papan_menyebut_keadaan_arsipnya(): void
    {
        [$title, $p] = $this->naskah();
        app(TitleProgressService::class)->advance($p, $this->superadmin());

        $isi = $this->actingAs($this->superadmin())
            ->get(route('naskah.pelacakan', ['tipe' => 'artikel']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('arsip belum diajukan', $isi);
    }

    /** @test */
    public function kartu_menyebut_menunggu_acc_saat_sudah_diajukan(): void
    {
        [$title, $p] = $this->naskah();
        $sa = $this->superadmin();
        app(TitleProgressService::class)->advance($p, $sa);
        TitleArchive::create(['title_id' => $title->id, 'status' => 'diajukan', 'submitted_by' => $sa->id]);

        $isi = $this->actingAs($sa)
            ->get(route('naskah.pelacakan', ['tipe' => 'artikel']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('arsip menunggu ACC', $isi);
    }

    // ─── migrasi ───

    /** @test */
    public function migrasi_mengembalikan_yang_arsipnya_belum_disetujui(): void
    {
        [$belum, $pBelum]     = $this->naskah('publish');
        [$disetujui, $pAcc]   = $this->naskah('publish');

        // Keduanya terlanjur terarsip seperti data lama.
        DB::table('tb_title_progress')->whereIn('id', [$pBelum->id, $pAcc->id])
            ->update(['archived_at' => now()]);

        TitleArchive::create(['title_id' => $disetujui->id, 'status' => 'disetujui',
                              'approved_by' => $this->superadmin()->id, 'approved_at' => now()]);

        $migrasi = include database_path('migrations/2026_08_23_000001_kembalikan_naskah_terbit_ke_papan.php');
        $migrasi->up();

        $this->assertNull($pBelum->fresh()->archived_at,
            'Arsipnya tak pernah disetujui — naskahnya harus kembali terlihat.');
        $this->assertNotNull($pAcc->fresh()->archived_at,
            'Yang arsipnya sudah disetujui memang selesai — jangan ditarik kembali.');
    }
}
