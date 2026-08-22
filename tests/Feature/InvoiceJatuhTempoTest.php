<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A5 — status `jatuh_tempo` tak boleh menunggu ada orang yang ingat.
 *
 * `Invoice::isOverdue()` sudah ada dan benar, tapi TAK ADA satu pun pemanggilnya:
 * status itu hanya bisa muncul kalau seseorang menyetelnya tangan. Keterlambatan
 * naskah sudah ketahuan sendiri tiap pagi lewat `naskah:check-overdue`; tagihan
 * lewat tempo tidak punya padanannya.
 */
class InvoiceJatuhTempoTest extends TestCase
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

    private function invoice(string $status, string $dueAt): Invoice
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        $order = Order::create([
            'code_order' => 'ORD-JT-' . fake()->unique()->numerify('####'),
            'user_id' => $u->id, 'status' => 'pending', 'ordered_at' => '2026-01-01',
        ]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Buku JT',
            'slug' => 'buku-jt-' . $order->id, 'naskah_type' => 'mandiri',
            'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);

        return Invoice::create([
            'order_id' => $order->id, 'invoice_no' => 'INV-JT-' . $order->id,
            'status' => $status, 'issued_at' => '2026-01-01', 'due_at' => $dueAt,
        ]);
    }

    /** @test */
    public function invoice_lewat_tempo_ditandai_jatuh_tempo(): void
    {
        $inv = $this->invoice('diterbitkan', now()->subDay()->toDateString());

        $this->artisan('invoice:check-overdue')->assertExitCode(0);

        $this->assertSame('jatuh_tempo', $inv->fresh()->status);
    }

    /**
     * Setiap perubahan status invoice lain sudah punya jejak. Yang otomatis tak boleh
     * jadi pengecualian — justru perubahan tanpa saksi yang paling perlu dicatat.
     *
     * @test
     */
    public function perubahan_otomatis_meninggalkan_jejak_di_invoice_log(): void
    {
        $inv = $this->invoice('diterbitkan', now()->subDays(3)->toDateString());

        $this->artisan('invoice:check-overdue');

        $this->assertDatabaseHas('tb_invoice_logs', [
            'invoice_id'  => $inv->id,
            'from_status' => 'diterbitkan',
            'to_status'   => 'jatuh_tempo',
        ]);
    }

    /** @test */
    public function invoice_yang_belum_lewat_tempo_tak_disentuh(): void
    {
        $inv = $this->invoice('diterbitkan', now()->addDays(7)->toDateString());

        $this->artisan('invoice:check-overdue');

        $this->assertSame('diterbitkan', $inv->fresh()->status);
    }

    /** @test */
    public function invoice_lunas_dan_dibatalkan_tak_pernah_jatuh_tempo(): void
    {
        $lunas  = $this->invoice('lunas', now()->subDays(30)->toDateString());
        $batal  = $this->invoice('dibatalkan', now()->subDays(30)->toDateString());

        $this->artisan('invoice:check-overdue');

        $this->assertSame('lunas', $lunas->fresh()->status);
        $this->assertSame('dibatalkan', $batal->fresh()->status);
    }

    /** @test */
    public function pemilik_order_diberi_tahu(): void
    {
        $inv    = $this->invoice('diterbitkan', now()->subDay()->toDateString());
        $pemilik = $inv->order->user;

        $this->artisan('invoice:check-overdue');

        $this->assertSame(1, $pemilik->fresh()->notifications()->count(),
            'Yang bertanggung jawab menagih harus tahu, bukan menemukannya sendiri.');
    }

    /** @test */
    public function dijalankan_dua_kali_tak_menggandakan_jejak(): void
    {
        $inv = $this->invoice('diterbitkan', now()->subDay()->toDateString());

        $this->artisan('invoice:check-overdue');
        $this->artisan('invoice:check-overdue');

        $this->assertSame(1, \App\Models\InvoiceLog::where('invoice_id', $inv->id)
            ->where('to_status', 'jatuh_tempo')->count(),
            'Cron jalan tiap hari; riwayatnya tak boleh membengkak tiap pagi.');
    }
}
