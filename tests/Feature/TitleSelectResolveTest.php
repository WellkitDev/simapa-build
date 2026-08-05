<?php

namespace Tests\Feature;

use App\Models\Title;
use App\Models\User;
use App\Services\TitleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TitleSelectResolveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function actor(): User
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        return $u;
    }

    private function ctx(): array
    {
        return ['jenis' => 'buku', 'order_type' => 'bk_mandiri', 'scope_id' => null, 'indeksasi' => null];
    }

    private function existingTitle(User $actor, string $title = 'Judul Lama', string $code = 'JL'): Title
    {
        return Title::create([
            'title' => $title, 'code' => $code, 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui', 'asal' => 'order',
            'created_by' => $actor->id,
        ]);
    }

    /** @test */
    public function nilai_berprefix_new_menghasilkan_judul_baru(): void
    {
        $actor = $this->actor();

        $title = app(TitleService::class)->resolveForOrder('new:Judul Segar', $this->ctx(), $actor);

        $this->assertSame('Judul Segar', $title->title);
        $this->assertSame('order', $title->asal);
        $this->assertSame('disetujui', $title->status);
    }

    /** @test */
    public function nilai_angka_menaut_ke_judul_yang_ada(): void
    {
        $actor = $this->actor();
        $ada   = $this->existingTitle($actor);

        $title = app(TitleService::class)->resolveForOrder((string) $ada->id, $this->ctx(), $actor);

        $this->assertTrue($title->is($ada));
        $this->assertSame(1, Title::count());
    }

    /** @test */
    public function judul_bernama_angka_jadi_judul_baru_bukan_id(): void
    {
        $actor = $this->actor();
        $ada   = $this->existingTitle($actor);

        // Dulu: "2026" lolos is_numeric() → dibaca sebagai id judul.
        $title = app(TitleService::class)->resolveForOrder('new:2026', $this->ctx(), $actor);

        $this->assertSame('2026', $title->title);
        $this->assertFalse($title->is($ada));
        $this->assertSame(2, Title::count());
    }

    /** @test */
    public function string_polos_tetap_diterima_jalur_kompatibilitas(): void
    {
        $actor = $this->actor();

        $title = app(TitleService::class)->resolveForOrder('Judul Tanpa Prefix', $this->ctx(), $actor);

        $this->assertSame('Judul Tanpa Prefix', $title->title);
    }

    /** @test */
    public function judul_kembar_nama_dan_jenis_sama_dipakai_ulang(): void
    {
        $actor = $this->actor();
        $ada   = $this->existingTitle($actor, 'Judul Kembar', 'JK');

        $title = app(TitleService::class)->resolveForOrder('new:Judul Kembar', $this->ctx(), $actor);

        $this->assertTrue($title->is($ada));
        $this->assertSame(1, Title::count());
    }

    /** @test */
    public function judul_kembar_beda_jenis_tidak_dipakai_ulang(): void
    {
        $actor = $this->actor();
        $buku  = $this->existingTitle($actor, 'Judul Sama', 'JS');

        $artikel = app(TitleService::class)->resolveForOrder(
            'new:Judul Sama',
            ['jenis' => 'artikel', 'order_type' => 'at_mandiri', 'scope_id' => null, 'indeksasi' => null],
            $actor
        );

        $this->assertFalse($artikel->is($buku));
        $this->assertSame('artikel', $artikel->jenis);
    }

    /** @test */
    public function nama_judul_untuk_validasi_dipangkas_dari_prefix(): void
    {
        $actor = $this->actor();
        $ada   = $this->existingTitle($actor, 'Judul Ber-ID', 'JBI');

        $svc = app(TitleService::class);
        $this->assertSame('Judul Baru', $svc->titleNameFrom('new:Judul Baru'));
        $this->assertSame('Judul Ber-ID', $svc->titleNameFrom((string) $ada->id));
        $this->assertSame('Judul Polos', $svc->titleNameFrom('Judul Polos'));
        $this->assertSame('', $svc->titleNameFrom('new:   '));
    }
}
