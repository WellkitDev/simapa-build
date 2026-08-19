<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pemilik order (`tb_orders.user_id`) boleh dipilih superadmin saat membuat DAN
 * mengedit order.
 *
 * Kolom ini bukan sekadar label "Marketing": MarketingTargetService menghitung
 * realisasi & komisi dari `Payment::income()->forOrdersOf($user)`, dan marketing
 * hanya melihat order dengan user_id miliknya. Jadi siapa pemiliknya menentukan
 * komisi jatuh ke siapa dan siapa yang bisa melihat ordernya — karena itu role
 * selain superadmin tidak boleh bisa menyetelnya sama sekali, bahkan dengan POST
 * langsung.
 */
class OrderPemilikTest extends TestCase
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

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u->fresh();
    }

    private function bookPayload(array $over = []): array
    {
        return array_merge([
            'type' => 'bk_mandiri', 'title_id' => 'Judul Order Buku', 'scope_id' => '',
            'chapters' => 3, 'naskah_type' => 'dibuatkan', 'publication_type' => 'regular',
            'issued_at' => '2026-07-02', 'cost_amount' => 1000000,
            'contact_phone' => '08123', 'contact_email' => 'c@example.com',
            'authors' => [['name' => 'A', 'email' => 'a@example.com', 'position' => 1]],
        ], $over);
    }

    private function journalPayload(array $over = []): array
    {
        return array_merge([
            'type' => 'at_kolab', 'title_id' => 'Artikel Order', 'scope_id' => '',
            'indexation' => 'sinta 2', 'naskah_type' => 'dibuatkan', 'publication_type' => 'regular',
            'issued_at' => '2026-07-02', 'cost_amount' => 500000,
            'contact_phone' => '08123', 'contact_email' => 'j@example.com',
            'authors' => [['name' => 'A', 'email' => 'a@example.com', 'position' => 1]],
        ], $over);
    }

    // ─── membuat ───

    /** @test */
    public function superadmin_menugaskan_order_buku_ke_marketing_lain(): void
    {
        $super = $this->user('superadmin');
        $mkt   = $this->user('marketing');

        $this->actingAs($super)
            ->post(route('order.book.store'), $this->bookPayload(['user_id' => $mkt->id]));

        $this->assertSame($mkt->id, Order::first()->user_id);
    }

    /** @test */
    public function superadmin_menugaskan_order_buku_ke_diri_sendiri(): void
    {
        $super = $this->user('superadmin');
        $this->user('marketing');

        $this->actingAs($super)
            ->post(route('order.book.store'), $this->bookPayload(['user_id' => $super->id]));

        $this->assertSame($super->id, Order::first()->user_id);
    }

    /** @test */
    public function tanpa_memilih_pemilik_order_jatuh_ke_pembuatnya(): void
    {
        $super = $this->user('superadmin');

        $this->actingAs($super)->post(route('order.book.store'), $this->bookPayload());

        $this->assertSame($super->id, Order::first()->user_id);
    }

    /** @test */
    public function superadmin_menugaskan_order_jurnal_ke_marketing_lain(): void
    {
        $super = $this->user('superadmin');
        $mkt   = $this->user('marketing');

        $this->actingAs($super)
            ->post(route('order.journal.store'), $this->journalPayload(['user_id' => $mkt->id]));

        $this->assertSame($mkt->id, Order::first()->user_id);
    }

    // ─── gerbang: hanya superadmin ───

    /** @test */
    public function marketing_tidak_bisa_memalsukan_pemilik_order(): void
    {
        $mkt  = $this->user('marketing');
        $lain = $this->user('marketing');

        $this->actingAs($mkt)
            ->post(route('order.book.store'), $this->bookPayload(['user_id' => $lain->id]));

        $this->assertSame($mkt->id, Order::first()->user_id,
            'POST langsung dari marketing tak boleh memindahkan komisi ke orang lain.');
    }

    /**
     * Manager, bukan admin: admin sama sekali tak punya `order.create` (dicek lewat
     * AccessMatrixSeeder), jadi menguji admin di sini hanya menguji gerbang route,
     * bukan gerbang pemilik. Manager justru kasus yang menarik — ia memegang hibah
     * wildcard '*', jadi satu-satunya yang menahannya adalah pemeriksaan role
     * superadmin di OrderOwnerService.
     *
     * @test
     */
    public function manager_tidak_bisa_memalsukan_pemilik_order(): void
    {
        $manager = $this->user('manager');
        $mkt     = $this->user('marketing');

        $this->actingAs($manager)
            ->post(route('order.book.store'), $this->bookPayload(['user_id' => $mkt->id]));

        $this->assertSame($manager->id, Order::first()->user_id);
    }

    /** @test */
    public function pemilik_di_luar_daftar_ditolak(): void
    {
        $super    = $this->user('superadmin');
        $produksi = $this->user('production');

        $this->actingAs($super)
            ->post(route('order.book.store'), $this->bookPayload(['user_id' => $produksi->id]))
            ->assertSessionHasErrors('user_id');

        $this->assertSame(0, Order::count(),
            'User non-marketing tak punya target/komisi, jadi order tak boleh mendarat di sana.');
    }

    // ─── formulir ───

    /** @test */
    public function superadmin_melihat_pilihan_pemilik_di_formulir_buat_order(): void
    {
        $super = $this->user('superadmin');
        $mkt   = $this->user('marketing');

        $this->actingAs($super)->get(route('order.book.create'))
            ->assertOk()
            ->assertSee('Pemilik Order (Marketing)')
            ->assertSee($mkt->name);
    }

    /** @test */
    public function marketing_tidak_melihat_pilihan_pemilik(): void
    {
        $this->user('superadmin');
        $mkt = $this->user('marketing');

        $this->actingAs($mkt)->get(route('order.book.create'))
            ->assertOk()
            ->assertDontSee('Pemilik Order (Marketing)');
    }

    /** @test */
    public function formulir_jurnal_juga_menampilkan_pilihan_pemilik_bagi_superadmin(): void
    {
        $super = $this->user('superadmin');

        $this->actingAs($super)->get(route('order.journal.create'))
            ->assertOk()
            ->assertSee('Pemilik Order (Marketing)');
    }

    // ─── mengedit ───

    /** @test */
    public function superadmin_memindahkan_pemilik_order_saat_edit(): void
    {
        $super = $this->user('superadmin');
        $lama  = $this->user('marketing');
        $baru  = $this->user('marketing');

        $this->actingAs($super)
            ->post(route('order.book.store'), $this->bookPayload(['user_id' => $lama->id]));
        $order = Order::first();
        $this->assertSame($lama->id, $order->user_id);

        $this->actingAs($super)->put(
            route('order.book.update', $order->id),
            $this->bookPayload(['user_id' => $baru->id])
        );

        $this->assertSame($baru->id, $order->fresh()->user_id);
    }

    /**
     * Pemilik order lama belum tentu ber-role marketing: di DB produksi 137 dari 152
     * order dimiliki superadmin, dan seorang marketing yang kelak pindah role akan
     * keluar dari daftar pilihan. Kalau daftar itu tak memuat pemilik sekarang,
     * <select> jatuh ke opsi pertama — dan menyimpan form edit tanpa menyentuh
     * apa pun akan MEMINDAHKAN order beserta komisinya ke orang lain.
     *
     * @test
     */
    public function pemilik_sekarang_selalu_ada_di_daftar_walau_bukan_marketing(): void
    {
        $super = $this->user('superadmin');
        $bukan = $this->user('production');

        $this->actingAs($super)->post(route('order.book.store'), $this->bookPayload());
        $order = Order::first();
        $order->forceFill(['user_id' => $bukan->id])->save();   // meniru data lama

        $this->actingAs($super)->get(route('order.book.edit', $order->code_order))
            ->assertOk()
            ->assertSee($bukan->name);
    }

    /** @test */
    public function menyimpan_edit_tanpa_mengubah_pemilik_non_marketing_tidak_memindahkannya(): void
    {
        $super = $this->user('superadmin');
        $bukan = $this->user('production');

        $this->actingAs($super)->post(route('order.book.store'), $this->bookPayload());
        $order = Order::first();
        $order->forceFill(['user_id' => $bukan->id])->save();

        $this->actingAs($super)->put(
            route('order.book.update', $order->id),
            $this->bookPayload(['user_id' => $bukan->id])
        )->assertSessionHasNoErrors();

        $this->assertSame($bukan->id, $order->fresh()->user_id);
    }

    /** @test */
    public function marketing_tidak_bisa_memindahkan_pemilik_saat_edit(): void
    {
        $mkt  = $this->user('marketing');
        $lain = $this->user('marketing');

        $this->actingAs($mkt)->post(route('order.book.store'), $this->bookPayload());
        $order = Order::first();

        $this->actingAs($mkt)->put(
            route('order.book.update', $order->id),
            $this->bookPayload(['user_id' => $lain->id])
        );

        $this->assertSame($mkt->id, $order->fresh()->user_id);
    }
}
