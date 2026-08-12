<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function invoice(): ServiceInvoice
    {
        $inv = ServiceInvoice::factory()->create();
        $inv->items()->create(['name' => 'Setup Lengkap Jurnal', 'qty' => 1, 'unit_price' => 2500000, 'subtotal' => 2500000]);
        $inv->recalcTotals();

        return $inv->refresh();
    }

    /** @test */
    public function recording_a_dp_then_a_pelunasan_walks_the_status_to_lunas(): void
    {
        $inv     = $this->invoice();
        $manager = $this->user('manager');

        $this->actingAs($manager)->post(route('service.invoice.payment.store', $inv->id), [
            'paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => '1.000.000', 'method' => 'transfer',
        ])->assertRedirect();

        $inv->refresh();
        $this->assertEquals(1000000, $inv->paid_total);      // pemisah ribuan dibuang
        $this->assertEquals(1500000, $inv->remaining);
        $this->assertSame('dp', $inv->payment_status);
        $this->assertSame('payment_added', $inv->logs->first()->event);

        $this->actingAs($manager)->post(route('service.invoice.payment.store', $inv->id), [
            'paid_at' => '2026-08-25', 'type' => 'pelunasan', 'amount' => '1500000', 'method' => 'transfer',
        ])->assertRedirect();

        $inv->refresh();
        $this->assertSame('lunas', $inv->payment_status);
        $this->assertEquals(0, $inv->remaining);
        $this->assertCount(2, $inv->payments);
    }

    /** @test */
    public function deleting_a_payment_recalculates_and_leaves_a_trace(): void
    {
        $inv = $this->invoice();
        $pay = $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 1000000]);
        $inv->recalcTotals();

        $this->actingAs($this->user('manager'))
            ->delete(route('service.invoice.payment.destroy', [$inv->id, $pay->id]))
            ->assertRedirect();

        $inv->refresh();
        $this->assertEquals(0, $inv->paid_total);
        $this->assertSame('belum', $inv->payment_status);
        $this->assertSoftDeleted('tb_service_invoice_payments', ['id' => $pay->id]);
        $this->assertSame('payment_deleted', $inv->logs->first()->event);
    }

    /** @test */
    public function payment_deleted_log_records_the_fresh_status_not_the_stale_one(): void
    {
        $inv = $this->invoice();
        $pay = $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'pelunasan', 'amount' => 2500000]);
        $inv->recalcTotals();
        $this->assertSame('lunas', $inv->fresh()->payment_status);

        $this->actingAs($this->user('manager'))
            ->delete(route('service.invoice.payment.destroy', [$inv->id, $pay->id]))
            ->assertRedirect();

        $log = $inv->fresh()->logs->first();
        $this->assertSame('belum', $log->to_status);
    }

    /** @test */
    public function overpayment_is_accepted_and_flagged(): void
    {
        $inv = $this->invoice();

        $this->actingAs($this->user('manager'))->post(route('service.invoice.payment.store', $inv->id), [
            'paid_at' => '2026-08-12', 'type' => 'pelunasan', 'amount' => '2550000', 'method' => 'transfer',
        ])->assertRedirect();

        $inv->refresh();
        $this->assertSame('lunas', $inv->payment_status);
        $this->assertTrue($inv->isOverpaid());
        $this->assertEquals(50000, $inv->overpaidAmount());
    }

    /**
     * Risiko 2 (brief tugas): recalcTotals() dipanggil SEBELUM baris log ditulis,
     * dan forceFill()->save() menulis ke instance yang sama in-memory. Kalau
     * urutannya suatu saat dibalik (log dulu, baru recalc), to_status akan
     * membeku di status LAMA ('belum') walau statusnya sudah 'lunas'.
     */
    /** @test */
    public function payment_added_log_records_the_fresh_status_not_the_stale_one(): void
    {
        $inv = $this->invoice();

        $this->actingAs($this->user('manager'))->post(route('service.invoice.payment.store', $inv->id), [
            'paid_at' => '2026-08-12', 'type' => 'pelunasan', 'amount' => '2500000', 'method' => 'transfer',
        ])->assertRedirect();

        $log = $inv->fresh()->logs->first();
        $this->assertSame('lunas', $log->to_status);
    }

    /** @test */
    public function marketing_has_no_permission_to_record_or_delete_payments(): void
    {
        $inv = $this->invoice();

        $this->actingAs($this->user('marketing'))->post(route('service.invoice.payment.store', $inv->id), [
            'paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => '500000', 'method' => 'transfer',
        ])->assertRedirect();
        $this->assertCount(0, $inv->fresh()->payments);

        $pay = $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 500000]);
        $inv->recalcTotals();

        $this->actingAs($this->user('marketing'))
            ->delete(route('service.invoice.payment.destroy', [$inv->id, $pay->id]))
            ->assertRedirect();
        $this->assertNotSoftDeleted('tb_service_invoice_payments', ['id' => $pay->id]);
    }

    /** @test */
    public function cancelled_invoice_refuses_new_payments(): void
    {
        $inv = $this->invoice();
        $inv->update(['work_status' => 'batal']);

        $this->actingAs($this->user('manager'))->post(route('service.invoice.payment.store', $inv->id), [
            'paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => '500000', 'method' => 'transfer',
        ])->assertRedirect();

        $this->assertCount(0, $inv->fresh()->payments);
    }

    /** @test */
    public function zero_and_negative_amounts_are_rejected(): void
    {
        $inv = $this->invoice();

        foreach (['0', '-500000'] as $amount) {
            $this->actingAs($this->user('manager'))->post(route('service.invoice.payment.store', $inv->id), [
                'paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => $amount, 'method' => 'transfer',
            ])->assertSessionHasErrors('amount');
        }

        $this->assertCount(0, $inv->fresh()->payments);
    }

    /**
     * Risiko 4 (brief tugas): amount kosong atau larik tidak boleh ikut lolos ke
     * preg_replace/DB — harus jadi galat validasi rapi, bukan 500. Task 8 menambal
     * jebakan serupa di ServiceInvoiceForm::normalize() dengan is_scalar().
     */
    /** @test */
    public function empty_amount_is_rejected_without_a_server_error(): void
    {
        $inv = $this->invoice();

        $this->actingAs($this->user('manager'))->post(route('service.invoice.payment.store', $inv->id), [
            'paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => '', 'method' => 'transfer',
        ])->assertSessionHasErrors('amount');

        $this->assertCount(0, $inv->fresh()->payments);
    }

    /** @test */
    public function array_amount_is_rejected_without_a_server_error(): void
    {
        $inv = $this->invoice();

        $this->actingAs($this->user('manager'))->post(route('service.invoice.payment.store', $inv->id), [
            'paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => ['1000000'], 'method' => 'transfer',
        ])->assertSessionHasErrors('amount');

        $this->assertCount(0, $inv->fresh()->payments);
    }

    /**
     * Aturan global 13: blade yang gagal DIKOMPILASI hanya terlihat kalau view-nya
     * benar-benar dirender — tak satu pun tes di atas ini pernah GET halaman detail,
     * jadi panel & modal pembayaran yang baru ditambahkan di show.blade.php belum
     * pernah benar-benar dirender oleh test manapun. superadmin ikut diuji karena
     * Gate::before membuatnya menempuh jalur berbeda dari manager (aturan global 10).
     */
    /** @test */
    public function payment_panel_and_modal_render_for_manager_and_superadmin(): void
    {
        $inv = $this->invoice();
        $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 1000000]);
        $inv->recalcTotals();

        foreach (['manager', 'superadmin'] as $role) {
            $response = $this->actingAs($this->user($role))
                ->get(route('service.invoice.show', $inv->id))
                ->assertOk();

            $response->assertSee('Riwayat Pembayaran');
            $response->assertSee('Catat Pembayaran');
            $response->assertSee('paymentModal', false);
        }
    }

    /**
     * Aturan global 12: kalau prefill jumlah di modal mengirim $invoice->remaining
     * mentah (cast decimal:2, "1500000.00"), pembersih pemisah ribuan di controller
     * membuang titik desimalnya dan angkanya jadi 100x saat form itu di-submit ulang
     * tanpa diubah operator. Nilainya harus int di HTML yang dirender.
     */
    /** @test */
    public function the_amount_modal_prefills_the_int_remaining_not_the_decimal_string(): void
    {
        $inv = $this->invoice();
        $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 1000000]);
        $inv->recalcTotals();
        $this->assertSame('1500000.00', (string) $inv->fresh()->remaining, 'pra-syarat: cast decimal:2 harus string ".00".');

        $html = $this->actingAs($this->user('manager'))
            ->get(route('service.invoice.show', $inv->id))
            ->getContent();

        $this->assertSame(1, preg_match('/name="amount"[^>]*value="([^"]*)"/', $html, $m));
        $this->assertSame('1500000', $m[1], 'amount harus int "1500000", bukan "1500000.00".');
    }

    /**
     * @unless($invoice->isCancelled()) membungkus TOMBOL pembuka modal — modal-nya
     * sendiri (dengan judul "Catat Pembayaran" juga) tetap ada di DOM (dibungkus
     * @can, bukan @unless), cuma tak ada apa pun yang bisa membukanya. Jadi
     * assertion di sini menargetkan data-bs-target, bukan teks "Catat Pembayaran"
     * yang juga muncul di judul modal. Server-side sudah dijaga controller
     * (isCancelled() menolak store()) — ini murni afordans UI. Tak satu pun tes
     * lain merender halaman detail invoice yang sudah dibatalkan dengan @can aktif,
     * jadi cabang ini belum pernah benar-benar dilihat oleh test manapun.
     */
    /** @test */
    public function cancelled_invoice_hides_the_record_payment_button(): void
    {
        $inv = $this->invoice();
        $inv->update(['work_status' => 'batal', 'cancel_reason' => 'klien mundur']);

        $response = $this->actingAs($this->user('manager'))
            ->get(route('service.invoice.show', $inv->id))
            ->assertOk();

        $response->assertSee('Riwayat Pembayaran');
        $response->assertDontSee('data-bs-target="#paymentModal"', false);
    }
}
