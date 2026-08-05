<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StripCodePrefixCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function makeTitle(string $title, string $code): Title
    {
        $user = User::factory()->create();

        return Title::create([
            'title' => $title, 'code' => $code, 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'asal' => 'order',
            'created_by' => $user->id,
        ]);
    }

    /** @test */
    public function dry_run_tidak_mengubah_apa_pun(): void
    {
        $bersih = $this->makeTitle('Judul Bersih', 'JB');
        $kotor  = $this->makeTitle('JB — Judul Bersih', 'JBX');

        $this->artisan('titles:strip-code-prefix')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertSame('JB — Judul Bersih', $kotor->fresh()->title);
        $this->assertSame('Judul Bersih', $bersih->fresh()->title);
        $this->assertSame(0, TitleLog::count());
    }

    /** @test */
    public function apply_memangkas_hanya_baris_yang_kodenya_benar_benar_cocok(): void
    {
        $cocok = $this->makeTitle('JB — Judul Bersih', 'JB');

        // Kode "ZZZ" tidak terdaftar di tb_titles.code → tidak boleh disentuh.
        $palsu = $this->makeTitle('ZZZ — Judul Lain', 'QQ');

        $this->artisan('titles:strip-code-prefix --apply')->assertExitCode(0);

        $this->assertSame('Judul Bersih', $cocok->fresh()->title);
        $this->assertSame('ZZZ — Judul Lain', $palsu->fresh()->title);
        $this->assertDatabaseHas('tb_title_logs', [
            'title_id' => $cocok->id,
            'event'    => 'code_prefix_stripped',
        ]);
    }

    /** @test */
    public function judul_sah_bertanda_hubung_tidak_tersentuh(): void
    {
        $sah = $this->makeTitle('Pendidikan Anak Usia Dini — Sebuah Tinjauan', 'PAUD');

        $this->artisan('titles:strip-code-prefix --apply')->assertExitCode(0);

        $this->assertSame('Pendidikan Anak Usia Dini — Sebuah Tinjauan', $sah->fresh()->title);
    }

    /** @test */
    public function detail_order_ikut_dibersihkan(): void
    {
        $title  = $this->makeTitle('Judul Buku', 'JBK');
        $order  = Order::factory()->create();
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri',
            'title' => 'JBK — Judul Buku', 'slug' => 'judul-buku-' . $order->id,
            'title_id' => $title->id,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);

        $this->artisan('titles:strip-code-prefix --apply')->assertExitCode(0);

        $this->assertSame('Judul Buku', $detail->fresh()->title);
    }

    /** @test */
    public function tidak_ada_kandidat_dilaporkan_dengan_jelas(): void
    {
        $this->makeTitle('Judul Bersih Sekali', 'JBS');

        $this->artisan('titles:strip-code-prefix')
            ->expectsOutputToContain('Tidak ada judul berprefix kode')
            ->assertExitCode(0);
    }
}
