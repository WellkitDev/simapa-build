<?php
// tests/Feature/NaskahLayarTest.php

namespace Tests\Feature;

use App\Models\Author;
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
 * Asap keempat layar modul Penugasan Naskah: benar-benar ter-render dengan data nyata.
 * Uji izin ada di AccessParityTest; di sini yang dijaga adalah view-nya tidak pecah
 * dan identitas naskah muncul sebagai KODE ORDER di setiap layar.
 */
class NaskahLayarTest extends TestCase
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

    /** Artikel lengkap: order + author + PJ + pelaksana, siap dirender di semua layar. */
    private function naskah(string $status = 'editing', ?User $pj = null, ?User $pelaksana = null): TitleProgress
    {
        $title  = Title::create(['title' => 'Strategi Pemasaran UMKM Era AI', 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create(['user_id' => $this->user('marketing')->id, 'code_order' => 'ORD-2408-021']);
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => $title->title, 'title_id' => $title->id, 'indexation' => 'SINTA 4',
        ]);
        $detail->authors()->attach(Author::create(['name' => 'Sari, M.M.'])->id, ['position' => 1]);

        return TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'assigned_role' => TitleProgress::getHandlerForStatus($status),
            'bidang' => 'artikel', 'pj_user_id' => $pj?->id,
            'pelaksana_user_id' => $pelaksana?->id, 'priority' => 'high',
            'target_date' => now()->addDays(20), 'started_at' => now()->subDays(4),
        ]);
    }

    /** @test */
    public function meja_kerja_menampilkan_tugas_milik_sendiri_dan_statistik(): void
    {
        $admin = $this->user('admin', 'artikel');
        $this->naskah('editing', pj: $admin);

        $this->actingAs($admin)->get(route('naskah.workdesk'))
            ->assertOk()
            ->assertSee('Meja Kerja Saya')
            ->assertSee('Tugas Aktif')
            ->assertSee('Terlambat')
            ->assertSee('Deadline Minggu Ini')
            ->assertSee('Selesai Bulan Ini')
            ->assertSee('ORD-2408-021')
            ->assertSee('Strategi Pemasaran UMKM Era AI');
    }

    /** @test */
    public function antrian_bisa_diambil_hanya_tampil_untuk_produksi(): void
    {
        $this->naskah('menunggu_proses');

        $this->actingAs($this->user('production'))->get(route('naskah.workdesk'))
            ->assertOk()
            ->assertSee('Antrian Belum Ditugaskan');

        $this->actingAs($this->user('admin', 'artikel'))->get(route('naskah.workdesk'))
            ->assertOk()
            ->assertDontSee('Antrian Belum Ditugaskan');
    }

    /** @test */
    public function papan_pelacakan_menampilkan_zona_dan_kartu_berkode_order(): void
    {
        $this->naskah('editing', pj: $this->user('admin', 'artikel'));

        $this->actingAs($this->user('marketing'))->get(route('naskah.pelacakan'))
            ->assertOk()
            ->assertSee('Pelacakan Naskah')
            ->assertSee('Antrian')
            ->assertSee('Produksi')
            ->assertSee('Finalisasi')
            ->assertSee('ORD-2408-021');
    }

    /** @test */
    public function papan_buku_punya_zona_per_bab_dan_level_buku(): void
    {
        $this->actingAs($this->user('admin', 'buku'))
            ->get(route('naskah.pelacakan', ['tipe' => 'buku']))
            ->assertOk()
            ->assertSee('Produksi per Bab')
            ->assertSee('Produksi Level Buku');
    }

    /** @test */
    public function papan_tidak_menampilkan_naskah_yang_diarsipkan_atau_dibatalkan(): void
    {
        $p = $this->naskah('publish');
        $p->update(['archived_at' => now()]);

        $this->actingAs($this->user('admin', 'artikel'))->get(route('naskah.pelacakan'))
            ->assertOk()
            ->assertDontSee('ORD-2408-021');
    }

    /** @test */
    public function arsip_menampilkan_naskah_selesai_dan_bisa_difilter_ke_dibatalkan(): void
    {
        $p = $this->naskah('publish');
        $p->update(['archived_at' => now()]);

        $this->actingAs($this->user('marketing'))->get(route('naskah.arsip'))
            ->assertOk()
            ->assertSee('Arsip Naskah')
            ->assertSee('ORD-2408-021');

        $this->actingAs($this->user('marketing'))->get(route('naskah.arsip', ['hanya' => 'batal']))
            ->assertOk()
            ->assertDontSee('ORD-2408-021');
    }

    /** @test */
    public function tampilan_daftar_dan_riwayat_ter_render(): void
    {
        $this->naskah('editing');

        foreach (['daftar', 'riwayat'] as $view) {
            $this->actingAs($this->user('admin', 'artikel'))
                ->get(route('naskah.pelacakan', ['view' => $view]))
                ->assertOk();
        }
    }

    /** @test */
    public function detail_menampilkan_satu_tombol_maju_dengan_label_tahap_berikutnya(): void
    {
        $admin = $this->user('admin', 'artikel');
        $p     = $this->naskah('editing', pj: $admin);

        $this->actingAs($admin)->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            ->assertSee('ORD-2408-021')
            ->assertSee('Selesaikan Editing → lanjut ke Submit')
            ->assertSee('Riwayat (semua aksi tercatat)');
    }

    /** @test */
    public function marketing_melihat_detail_tanpa_blok_aksi(): void
    {
        $mkt = $this->user('marketing');
        $p   = $this->naskah('editing');

        $this->actingAs($mkt)->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            ->assertSee('ORD-2408-021')
            ->assertDontSee('Selesaikan Editing')
            ->assertDontSee('Batalkan naskah')
            // Marketing tetap boleh menetapkan target (request klien) dan mengunggah naskah.
            ->assertSee('Target publish');
    }

    /** @test */
    public function koreksi_tahap_hanya_dirender_untuk_superadmin(): void
    {
        $p = $this->naskah('submit', pj: $this->user('admin', 'artikel'));

        $this->actingAs($this->user('superadmin'))->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->assertSee('Koreksi tahap');

        $this->actingAs($this->user('admin', 'artikel'))->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->assertDontSee('Koreksi tahap');
    }

    /** @test */
    public function banner_grup_muncul_saat_satu_judul_punya_banyak_order(): void
    {
        $p     = $this->naskah('editing', pj: $this->user('admin', 'artikel'));
        $title = $p->orderDetail->titleRef;

        foreach (['ORD-2408-024', 'ORD-2408-027'] as $kode) {
            $order  = Order::factory()->create(['user_id' => $this->user('marketing')->id, 'code_order' => $kode]);
            $detail = OrderDetail::factory()->create([
                'order_id' => $order->id, 'type' => 'at_mandiri',
                'title' => $title->title, 'title_id' => $title->id,
            ]);
            TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => 'editing',
                'assigned_role' => 'production', 'bidang' => 'artikel', 'started_at' => now(),
            ]);
        }

        $this->actingAs($this->user('admin', 'artikel'))
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            ->assertSee('mencakup')
            ->assertSee('3 order')
            ->assertSee('ORD-2408-024');
    }
}
