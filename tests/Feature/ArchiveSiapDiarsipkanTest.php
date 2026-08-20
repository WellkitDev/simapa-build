<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Title;
use App\Models\TitleArchive;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Mengunci PINTU MASUK arsip.
 *
 * Sebelum ini `archive.show` cuma ditaut dari daftar judul yang SUDAH punya baris
 * tb_title_archives berstatus diajukan/disetujui — sementara satu-satunya cara membuat
 * baris itu adalah menekan "Ajukan ke Arsip" di halaman show. Lingkaran tertutup: judul
 * baru tak pernah bisa disiapkan arsipnya kecuali URL-nya diketik manual.
 *
 * Sekalian mengunci keputusan K2: pembayaran TIDAK menghalangi kemajuan naskah, tapi di
 * titik penutupan (arsip) kekurangannya harus kelihatan — dan harus menyebut ANGKA, bukan
 * sekadar "belum lunas", supaya penagihnya tahu berapa yang dikejar.
 */
class ArchiveSiapDiarsipkanTest extends TestCase
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

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    /** Judul artikel dengan satu order senilai 500rb yang naskahnya sudah publish. */
    private function judulFinal(string $judul, int $dibayar): Title
    {
        $title = Title::create([
            'title' => $judul, 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri',
            'status' => 'disetujui', 'slug' => 'j-' . uniqid(),
        ]);

        $this->tambahOrder($title, $dibayar);

        return $title->fresh();
    }

    /** Satu order (+pembayaran +progress) yang menempel pada judul. */
    private function tambahOrder(Title $title, int $dibayar, string $status = 'publish', bool $ditarik = false): OrderDetail
    {
        $order = Order::create([
            'code_order' => 'ORD-' . uniqid(), 'user_id' => $this->user('marketing')->id,
            'status' => 'pending', 'ordered_at' => now(),
        ]);

        $detail = OrderDetail::create([
            'order_id' => $order->id, 'title_id' => $title->id, 'type' => 'at_mandiri',
            'title' => $title->title, 'slug' => 'od-' . uniqid(), 'chapters' => 1,
            'cost_amount' => 500000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);

        if ($dibayar > 0) {
            Payment::create([
                'order_id' => $order->id, 'payment_type' => 'dp', 'amount' => $dibayar,
                'status' => 'paid', 'paid_at' => now(),
            ]);
        }

        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status, 'assigned_role' => 'production',
            'started_at' => now(), 'archived_at' => TitleProgress::isFinal($status) ? now() : null,
            'withdrawn_at' => $ditarik ? now() : null,
        ]);

        return $detail;
    }

    /** @test */
    public function judul_final_tanpa_arsip_muncul_di_siap_diarsipkan(): void
    {
        $title = $this->judulFinal('Naskah Siap Arsip', 500000);

        $this->actingAs($this->user('admin'))->get(route('archive.index'))
            ->assertOk()
            ->assertSee('Siap Diarsipkan')
            ->assertSee($title->title);
    }

    /** @test */
    public function kekurangan_bayar_disebut_angkanya(): void
    {
        // K2: bukan "belum lunas", tapi berapa kurangnya. 500rb - 200rb = 300rb.
        $this->judulFinal('Naskah Kurang Bayar', 200000);

        $this->actingAs($this->user('admin'))->get(route('archive.index'))
            ->assertOk()
            ->assertSee('300.000');
    }

    /** @test */
    public function judul_yang_belum_final_tidak_muncul(): void
    {
        $title = $this->judulFinal('Naskah Masih Editing', 500000);
        $title->orderDetails->first()->titleProgress
            ->update(['status' => 'editing', 'archived_at' => null]);

        $this->actingAs($this->user('admin'))->get(route('archive.index'))
            ->assertOk()
            ->assertDontSee($title->title);
    }

    /**
     * Judul yang arsipnya sudah diajukan sudah punya rumahnya sendiri di kartu
     * "Menunggu Persetujuan". Kalau ia juga nongol di "Siap Diarsipkan", satu judul
     * tampil dua kali di satu halaman dengan dua tombol berbeda — approver bingung
     * mana yang benar, dan tombol "Siapkan" mengundang pengajuan ulang yang sia-sia.
     *
     * @test
     */
    public function judul_yang_sudah_diajukan_tidak_muncul_lagi(): void
    {
        $title = $this->judulFinal('Naskah Sudah Diajukan', 500000);
        TitleArchive::create([
            'title_id' => $title->id, 'status' => 'diajukan',
            'submitted_by' => $this->user('admin')->id, 'submitted_at' => now(),
        ]);

        $this->actingAs($this->user('admin'))->get(route('archive.index'))
            ->assertOk()
            ->assertDontSee('Siap Diarsipkan');
    }

    /**
     * Kebalikannya: pengajuan yang DITOLAK harus kembali ke daftar kerja. Kalau tidak,
     * penolakan jadi jalan buntu — barisnya sudah ada sehingga daftar "siap" melewatinya,
     * padahal justru judul inilah yang perlu diperbaiki lalu diajukan lagi.
     *
     * @test
     */
    public function judul_yang_arsipnya_ditolak_muncul_lagi(): void
    {
        $title = $this->judulFinal('Naskah Ditolak Arsipnya', 500000);
        TitleArchive::create([
            'title_id' => $title->id, 'status' => 'ditolak', 'reject_note' => 'Artefak kurang',
        ]);

        $this->actingAs($this->user('admin'))->get(route('archive.index'))
            ->assertOk()
            ->assertSee('Siap Diarsipkan')
            ->assertSee($title->title);
    }

    /**
     * Order yang ditarik uangnya sudah dikembalikan, jadi ia tidak boleh menyeret judul
     * ini terlihat "kurang bayar" selamanya. Diuji lewat HALAMAN, bukan cuma unit, karena
     * pengecualian itu harus utuh dari `sisaTagihan()` sampai badge yang dibaca orang.
     *
     * @test
     */
    public function order_ditarik_tidak_ikut_dihitung_kurang_bayar(): void
    {
        $title = $this->judulFinal('Naskah Satu Penulis Mundur', 500000);
        $this->tambahOrder($title, 0, 'editing', true); // ditarik, belum bayar sepeser pun

        $segar = $title->fresh()->load(['orderDetails.titleProgress', 'orderDetails.order']);
        $this->assertSame(0, $segar->sisaTagihan(), 'Order ditarik tak boleh menambah sisa tagihan.');
        $this->assertSame(1, $segar->jumlahDitarik());

        $this->actingAs($this->user('admin'))->get(route('archive.index'))
            ->assertOk()
            ->assertSee($title->title)
            ->assertSee('1 ditarik')
            ->assertDontSee('Kurang Rp');
    }

    /**
     * K2 diuji di titik penutupan yang sebenarnya: halaman detail arsip. Di sinilah
     * orang memutuskan menutup pekerjaan, jadi di sinilah kekurangan bayar harus terbaca
     * beserta angkanya — di badge kelayakan maupun di baris ordernya.
     *
     * @test
     */
    public function detail_arsip_menyebut_angka_kekurangan(): void
    {
        $title = $this->judulFinal('Naskah Detail Kurang Bayar', 200000);

        $this->actingAs($this->user('admin'))->get(route('archive.show', $title->id))
            ->assertOk()
            ->assertSee('Belum Lunas — kurang Rp 300.000')
            ->assertSee('Kurang Rp 300.000');
    }

    /**
     * Order yang ditarik tetap tercantum di Info Order (riwayat tak boleh menguap) tapi
     * harus terbaca sebagai dicoret, bukan sebagai tunggakan yang menghantui judulnya.
     *
     * @test
     */
    public function detail_arsip_menandai_order_ditarik_bukan_kurang_bayar(): void
    {
        $title = $this->judulFinal('Naskah Detail Ada Yang Mundur', 500000);
        $this->tambahOrder($title, 0, 'editing', true);

        $this->actingAs($this->user('admin'))->get(route('archive.show', $title->id))
            ->assertOk()
            ->assertSee('Ditarik')
            ->assertSee('1 order ditarik')
            ->assertSee('Pembayaran Lunas')
            ->assertDontSee('Kurang Rp');
    }

    /**
     * Ubin "Arsip Menunggu Artefak" dulu menghitung TitleArchive berstatus 'draft' —
     * baris yang tak pernah dibuat kode mana pun, jadi angkanya abadi 0. Setelah dihidupkan
     * ia menaut ke daftar "Siap Diarsipkan", dan angka ubin WAJIB sama dengan panjang
     * daftar itu; kalau tidak, kita cuma menukar ubin mati dengan ubin bohong.
     *
     * @test
     */
    public function ubin_dashboard_sama_dengan_panjang_daftar_siap(): void
    {
        $this->judulFinal('Naskah Final Satu', 500000);
        $this->judulFinal('Naskah Final Dua', 500000);

        // Judul final yang arsipnya sudah disetujui tidak boleh ikut terhitung.
        $sudah = $this->judulFinal('Naskah Sudah Diarsipkan', 500000);
        TitleArchive::create(['title_id' => $sudah->id, 'status' => 'disetujui', 'approved_at' => now()]);

        // Judul yang SATU-SATUNYA order publish-nya ditarik: lolos pra-saring SQL,
        // tapi bukan judul final — inilah selisih yang membuat ubin bohong bila
        // penyaringan PHP-nya dilewati.
        $palsu = Title::create([
            'title' => 'Naskah Publish Tapi Ditarik', 'jenis' => 'artikel',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'slug' => 'p-' . uniqid(),
        ]);
        $this->tambahOrder($palsu, 500000, 'publish', true);

        $this->assertSame(2, Title::siapDiarsipkan()->count());
        $this->assertSame(2, app(AdminDashboardService::class)->forAdmin()['arsip_menunggu_artefak']);
    }
}
