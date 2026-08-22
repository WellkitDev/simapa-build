<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A13 — `paid_at` harus kembali sebagai Carbon, bukan string.
 *
 * Payment memakai `protected $dates = ['paid_at']`, yang TIDAK berfungsi lagi sejak
 * Laravel 10: getDates() hanya mengembalikan created_at/updated_at dan tak pernah
 * melirik properti itu. Akibatnya paid_at kembali sebagai string.
 *
 * Kerusakannya senyap karena pola yang dipakai di mana-mana adalah
 * `optional($p->paid_at)->format('d M Y') ?? '-'` — optional() pada string
 * mengembalikan pembungkus yang diam-diam memulangkan null, bukan melempar galat.
 * Jadi kolom Tanggal menampilkan "-", bukan pesan error, di seluruh laporan
 * pemasukan. Angkanya sendiri tetap benar karena KPI memakai SQL (whereYear), yang
 * tak lewat cast Eloquent — laporan yang "hampir benar" jauh lebih sulit dicurigai
 * daripada yang jelas rusak.
 */
class PaymentTanggalCastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function bayar(): Payment
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        $order = Order::create([
            'code_order' => 'ORD-CAST-' . fake()->unique()->numerify('####'),
            'user_id' => $u->id, 'status' => 'pending', 'ordered_at' => today()->toDateString(),
        ]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Buku Cast',
            'slug' => 'buku-cast-' . $order->id, 'naskah_type' => 'mandiri',
            'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);

        return Payment::create([
            'order_id' => $order->id, 'amount' => 400000, 'payment_type' => 'dp',
            'status' => 'paid', 'paid_at' => '2026-07-15 09:30:00',
        ]);
    }

    /** @test */
    public function paid_at_kembali_sebagai_carbon(): void
    {
        $this->assertInstanceOf(Carbon::class, $this->bayar()->fresh()->paid_at);
    }

    /**
     * Tes unit saja tak cukup: `optional($p->paid_at)->format(...)` memulangkan null
     * tanpa galat, jadi satu-satunya bukti bahwa tanggalnya benar-benar tampil adalah
     * melihat layarnya.
     *
     * @test
     */
    public function tanggal_benar_benar_tampil_di_laporan_pemasukan(): void
    {
        $this->bayar();
        $super = User::factory()->create();
        $super->assignRole('superadmin');

        $isi = $this->actingAs($super)->get(route('income.pemasukan'))->assertOk()->getContent();

        $this->assertStringContainsString('15 Jul 2026', $isi,
            'Kolom Tanggal di Laporan Pemasukan kosong — cast paid_at mati lagi.');
    }

    /**
     * `protected $dates` mati sejak Laravel 10 dan gagalnya senyap. Pagar ini menahan
     * siapa pun menghidupkannya kembali di model mana pun.
     *
     * @test
     */
    public function tak_ada_model_yang_memakai_dates_yang_sudah_mati(): void
    {
        $pelanggar = [];
        foreach (glob(app_path('Models/*.php')) as $berkas) {
            if (preg_match('/^\s*protected \$dates\s*=/m', (string) file_get_contents($berkas))) {
                $pelanggar[] = basename($berkas);
            }
        }

        $this->assertSame([], $pelanggar,
            'Pakai $casts, bukan $dates: $dates tidak berfungsi sejak Laravel 10 dan gagalnya tak bersuara.');
    }
}
