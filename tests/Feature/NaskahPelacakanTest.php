<?php
// tests/Feature/NaskahPelacakanTest.php

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
 * Layar 2 / 2B — Pelacakan Naskah. Papan ini menggantikan kanban lama yang membuat
 * orang bingung, jadi yang dijaga di sini: satu judul = satu kartu (kerja tidak dobel),
 * kartu duduk di kolom bottleneck, dan kartu menautkan ke Detail Naskah — bukan ke
 * halaman order seperti modul lama.
 */
class NaskahPelacakanTest extends TestCase
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

    /** Satu judul dengan N order (grup) — tiap order boleh beda tahap. */
    private function judul(string $nama, array $statuses, string $type = 'at_mandiri'): Title
    {
        $jenis = str_starts_with($type, 'bk_') ? 'buku' : 'artikel';
        $title = Title::create(['title' => $nama, 'jenis' => $jenis,
                                'tipe_naskah' => $type === 'bk_kolab' ? 'kolaborasi' : 'mandiri',
                                'status' => 'disetujui']);

        foreach ($statuses as $i => $status) {
            $order  = Order::factory()->create([
                'user_id' => $this->user('marketing')->id,
                'code_order' => 'ORD-TEST-' . strtoupper(substr(md5($nama . $i), 0, 5)),
            ]);
            $detail = OrderDetail::factory()->create([
                'order_id' => $order->id, 'type' => $type,
                'title' => $nama, 'title_id' => $title->id, 'chapters' => 3,
            ]);
            TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => $status,
                'assigned_role' => TitleProgress::getHandlerForStatus($status),
                'bidang' => $jenis === 'buku' ? 'buku' : 'artikel', 'started_at' => now(),
            ]);
        }

        return $title->fresh();
    }

    /** @test */
    public function satu_judul_bergrup_tampil_sebagai_satu_kartu_dengan_badge_jumlah_order(): void
    {
        $this->judul('Strategi Pemasaran UMKM', ['editing', 'editing', 'editing']);

        $isi = $this->actingAs($this->user('admin', 'artikel'))
            ->get(route('naskah.pelacakan'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($isi, 'Strategi Pemasaran UMKM'),
            'Judul bergrup harus muncul sebagai SATU kartu, bukan satu kartu per order.');
        $this->assertStringContainsString('3 order', $isi);
    }

    /** @test */
    public function kartu_duduk_di_kolom_tahap_paling_belakang_di_antara_order_sejudul(): void
    {
        // Satu order sudah submit, satu masih editing → kartu harus di Editing.
        $this->judul('Bottleneck Uji', ['submit', 'editing']);

        $res = $this->actingAs($this->user('admin', 'artikel'))
            ->get(route('naskah.pelacakan'))->assertOk();

        $kartu = $res->viewData('kartu');
        $this->assertTrue($kartu->has('editing'), 'Kartu harus duduk di kolom bottleneck (Editing).');
        $this->assertFalse($kartu->has('submit'));
    }

    /** @test */
    public function kartu_menautkan_ke_detail_naskah_bukan_ke_halaman_order(): void
    {
        $title = $this->judul('Tautan Uji', ['editing']);
        $p     = TitleProgress::whereHas('orderDetail', fn ($q) => $q->where('title_id', $title->id))->first();

        $this->actingAs($this->user('admin', 'artikel'))
            ->get(route('naskah.pelacakan'))
            ->assertOk()
            ->assertSee(route('naskah.show', $p->order_detail_id), false);
    }

    /** @test */
    public function zona_artikel_dan_buku_berbeda_sesuai_alur_kerjanya(): void
    {
        $admin = $this->user('admin', 'artikel');

        $this->actingAs($admin)->get(route('naskah.pelacakan', ['tipe' => 'artikel']))
            ->assertOk()
            ->assertSee('Revisi')->assertSee('Submit')->assertSee('LoA')
            ->assertDontSee('Produksi Level Buku');

        $this->actingAs($admin)->get(route('naskah.pelacakan', ['tipe' => 'buku']))
            ->assertOk()
            ->assertSee('Produksi per Bab')
            ->assertSee('Produksi Level Buku')
            ->assertSee('Proofreading')
            ->assertSee('Cetak');
    }

    /** @test */
    public function tab_buku_tidak_mencampur_artikel_dan_sebaliknya(): void
    {
        $this->judul('SEBUAH ARTIKEL', ['editing'], 'at_mandiri');
        $this->judul('SEBUAH BUKU', ['editing'], 'bk_mandiri');

        $admin = $this->user('admin', 'artikel');

        $this->actingAs($admin)->get(route('naskah.pelacakan', ['tipe' => 'artikel']))
            ->assertOk()->assertSee('SEBUAH ARTIKEL')->assertDontSee('SEBUAH BUKU');

        $this->actingAs($admin)->get(route('naskah.pelacakan', ['tipe' => 'buku']))
            ->assertOk()->assertSee('SEBUAH BUKU')->assertDontSee('SEBUAH ARTIKEL');
    }

    /** @test */
    public function kartu_buku_kolaborasi_menampilkan_ringkasan_bab(): void
    {
        $book = $this->judul('Buku Ekonomi Digital', ['pembuatan'], 'bk_kolab');
        foreach (['selesai', 'editing', 'menunggu'] as $i => $status) {
            $bab = $book->chapters()->create(['judul' => 'Bab ' . ($i + 1), 'urutan' => $i + 1]);
            $bab->authors()->attach(Author::create(['name' => 'Author ' . $i])->id, ['position' => 1]);
            $bab->progress()->create(['status' => $status, 'started_at' => now()]);
        }

        $this->actingAs($this->user('admin', 'buku'))
            ->get(route('naskah.pelacakan', ['tipe' => 'buku']))
            ->assertOk()
            ->assertSee('Buku Ekonomi Digital')
            ->assertSee('1 selesai')
            ->assertSee('1 editing')
            ->assertSee('1 menunggu');
    }

    /**
     * `naskah_type` melekat pada ORDER, sedangkan kartu papan mewakili satu GRUP judul.
     * Data nyata punya buku kolaborasi dengan 4 order "dibuatkan" + 1 "mandiri" — memakai
     * jenis satu order perwakilan membuat kartunya mengaku "Naskah Mandiri" dan
     * menyesatkan bagi empat order lainnya.
     *
     * @test
     */
    public function kartu_grup_tidak_mengaku_mandiri_saat_ordernya_campuran(): void
    {
        $title = $this->judul('Investasi Cerdas', ['pembuatan', 'pembuatan'], 'bk_kolab');
        $detail = \App\Models\OrderDetail::where('title_id', $title->id)->orderBy('id')->get();
        $detail[0]->update(['naskah_type' => 'mandiri']);
        $detail[1]->update(['naskah_type' => 'dibuatkan']);

        $res = $this->actingAs($this->user('admin', 'buku'))
            ->get(route('naskah.pelacakan', ['tipe' => 'buku']))->assertOk();

        $kartu = $res->viewData('kartu')->flatten(1)->firstWhere('progress.order_detail_id', $detail[0]->id)
            ?? $res->viewData('kartu')->flatten(1)->first();

        $this->assertSame('campuran', $kartu['jenisNaskah']);
        $res->assertSee('campuran antar order');
        $res->assertDontSee('Pelaksana: Naskah Mandiri');
    }

    /** @test */
    public function kartu_grup_menyebut_mandiri_hanya_bila_semua_ordernya_mandiri(): void
    {
        $title = $this->judul('Semua Mandiri', ['pembuatan', 'pembuatan'], 'bk_kolab');
        \App\Models\OrderDetail::where('title_id', $title->id)->update(['naskah_type' => 'mandiri']);

        $res = $this->actingAs($this->user('admin', 'buku'))
            ->get(route('naskah.pelacakan', ['tipe' => 'buku']))->assertOk();

        $this->assertSame('mandiri', $res->viewData('kartu')->flatten(1)->first()['jenisNaskah']);
        $res->assertSee('Naskah Mandiri');
    }

    /** @test */
    public function filter_pencarian_dan_prioritas_mempersempit_hasil(): void
    {
        $this->judul('NASKAH DICARI', ['editing']);
        $this->judul('NASKAH LAIN', ['editing']);

        $admin = $this->user('admin', 'artikel');

        $this->actingAs($admin)->get(route('naskah.pelacakan', ['cari' => 'DICARI']))
            ->assertOk()->assertSee('NASKAH DICARI')->assertDontSee('NASKAH LAIN');

        $this->actingAs($admin)->get(route('naskah.pelacakan', ['prioritas' => 'high']))
            ->assertOk()->assertDontSee('NASKAH DICARI');
    }

    /** @test */
    public function papan_tidak_menampilkan_yang_diarsipkan_maupun_dibatalkan(): void
    {
        $title = $this->judul('SUDAH SELESAI', ['publish']);
        TitleProgress::whereHas('orderDetail', fn ($q) => $q->where('title_id', $title->id))
            ->update(['archived_at' => now()]);

        $batal = $this->judul('SUDAH DIBATALKAN', ['editing']);
        TitleProgress::whereHas('orderDetail', fn ($q) => $q->where('title_id', $batal->id))
            ->update(['cancelled_at' => now(), 'cancel_reason' => 'klien batal']);

        $this->actingAs($this->user('admin', 'artikel'))->get(route('naskah.pelacakan'))
            ->assertOk()
            ->assertDontSee('SUDAH SELESAI')
            ->assertDontSee('SUDAH DIBATALKAN');
    }

    /** @test */
    public function arsip_memisahkan_naskah_selesai_dari_yang_dibatalkan(): void
    {
        $selesai = $this->judul('NASKAH TERBIT', ['publish']);
        TitleProgress::whereHas('orderDetail', fn ($q) => $q->where('title_id', $selesai->id))
            ->update(['archived_at' => now()]);

        $batal = $this->judul('NASKAH BATAL', ['editing']);
        TitleProgress::whereHas('orderDetail', fn ($q) => $q->where('title_id', $batal->id))
            ->update(['cancelled_at' => now(), 'cancel_reason' => 'klien mundur']);

        $mkt = $this->user('marketing');

        $this->actingAs($mkt)->get(route('naskah.arsip'))
            ->assertOk()->assertSee('NASKAH TERBIT')->assertDontSee('NASKAH BATAL');

        $this->actingAs($mkt)->get(route('naskah.arsip', ['hanya' => 'batal']))
            ->assertOk()->assertSee('NASKAH BATAL')->assertSee('klien mundur')
            ->assertDontSee('NASKAH TERBIT');
    }

    /** @test */
    public function papan_hanya_baca_untuk_marketing_tanpa_form_aksi(): void
    {
        $this->judul('Hanya Dilihat', ['editing']);

        $isi = $this->actingAs($this->user('marketing'))
            ->get(route('naskah.pelacakan'))->assertOk()->getContent();

        // Kerangka aplikasi (mis. form logout) tetap boleh POST — yang dilarang adalah
        // form aksi naskah: papan menautkan ke Detail, tidak mengubah apa pun sendiri.
        preg_match_all('/<form[^>]*action="([^"]*)"[^>]*>/i', $isi, $cocok);
        $aksiNaskah = collect($cocok[1])->filter(fn (string $url) => str_contains($url, '/naskah/'));

        $this->assertCount(0, $aksiNaskah,
            'Papan pelacakan harus hanya-baca — aksi ada di Detail Naskah.');
    }
}
