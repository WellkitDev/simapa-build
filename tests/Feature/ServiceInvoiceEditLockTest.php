<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoiceEditLockTest extends TestCase
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
        $inv->items()->create(['name' => 'Instalasi OJS', 'qty' => 1, 'unit_price' => 750000, 'subtotal' => 750000]);
        $inv->recalcTotals();

        return $inv->refresh();
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'client_name' => 'Klien Baru',
            'issued_at'   => '2026-08-11',
            'items'       => [['name' => 'Instalasi OJS', 'qty' => 1, 'unit_price' => '900000']],
        ], $override);
    }

    /** @test */
    public function fresh_invoice_is_editable_by_manager(): void
    {
        $inv = $this->invoice();
        $this->assertTrue($inv->isEditable());

        $manager = $this->user('manager');
        $this->actingAs($manager)->get(route('service.invoice.edit', $inv->id))->assertOk();

        $this->actingAs($manager)->put(route('service.invoice.update', $inv->id), $this->payload())
            ->assertRedirect(route('service.invoice.show', $inv->id));

        $inv->refresh();
        $this->assertEquals(900000, $inv->total);
        $this->assertSame('updated', $inv->logs->first()->event);
    }

    /** @test */
    public function invoice_with_a_payment_is_locked_for_manager(): void
    {
        $inv = $this->invoice();
        $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 300000]);
        $inv->recalcTotals();

        $this->assertFalse($inv->fresh()->isEditable());

        $manager = $this->user('manager');
        $this->actingAs($manager)->get(route('service.invoice.edit', $inv->id))
            ->assertRedirect(route('service.invoice.show', $inv->id));
        $this->actingAs($manager)->put(route('service.invoice.update', $inv->id), $this->payload())
            ->assertRedirect(route('service.invoice.show', $inv->id));

        $this->assertEquals(750000, $inv->fresh()->total);   // tidak berubah
    }

    /** @test */
    public function sent_invoice_is_locked_even_without_payments(): void
    {
        $inv = $this->invoice();
        $inv->update(['sent_at' => now(), 'sent_count' => 1]);

        $this->assertFalse($inv->fresh()->isEditable());
    }

    /** @test */
    public function superadmin_can_correct_a_locked_invoice_but_must_give_a_reason(): void
    {
        $inv = $this->invoice();
        $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 300000]);
        $inv->recalcTotals();

        $superadmin = $this->user('superadmin');

        // Tanpa alasan: ditolak.
        $this->actingAs($superadmin)->put(route('service.invoice.update', $inv->id), $this->payload())
            ->assertSessionHasErrors('correction_reason');
        $this->assertEquals(750000, $inv->fresh()->total);

        // Dengan alasan: lolos, dan alasannya tercatat.
        $this->actingAs($superadmin)->put(
            route('service.invoice.update', $inv->id),
            $this->payload(['correction_reason' => 'salah input harga, disepakati ulang'])
        )->assertRedirect(route('service.invoice.show', $inv->id));

        $inv->refresh();
        $this->assertEquals(900000, $inv->total);
        $this->assertStringContainsString('salah input harga', $inv->logs->first()->note);
    }

    /** @test */
    public function only_superadmin_can_delete_and_deletion_is_soft(): void
    {
        $inv = $this->invoice();

        // EnforcePermission menolak submit non-GET dengan redirect + flash error,
        // BUKAN 403 mentah (EnforcePermission::deny). Jadi yang diperiksa efeknya:
        // invoice-nya harus tetap ada.
        $this->actingAs($this->user('manager'))
            ->delete(route('service.invoice.destroy', $inv->id))
            ->assertRedirect();
        $this->assertNotSoftDeleted('tb_service_invoices', ['id' => $inv->id]);

        $this->actingAs($this->user('superadmin'))
            ->delete(route('service.invoice.destroy', $inv->id))
            ->assertRedirect(route('service.invoice.index'));

        $this->assertSoftDeleted('tb_service_invoices', ['id' => $inv->id]);
    }

    /**
     * Risiko 1 (brief tugas): tes di atas hanya me-render halaman edit sebagai
     * manager pada invoice yang masih editable. Gate::before membuat superadmin
     * menempuh jalur kode berbeda (aturan global 9/10) — kalau ada `route()` di
     * dalam `@can` yang belum lahir, atau logika lain yang cuma tersandung untuk
     * superadmin, tes manager-only tidak akan pernah melihatnya.
     */
    /** @test */
    public function edit_page_renders_for_superadmin_on_an_editable_invoice_with_items(): void
    {
        $inv = $this->invoice();

        $this->actingAs($this->user('superadmin'))
            ->get(route('service.invoice.edit', $inv->id))
            ->assertOk()
            ->assertSee('Instalasi OJS');
    }

    /**
     * Blok "Alasan Koreksi" (Step 7 rencana) hanya muncul kalau mode edit DAN
     * invoice terkunci. Tak satu pun tes lain benar-benar GET halaman edit pada
     * invoice terkunci — tes koreksi superadmin di atas cuma mem-PUT-nya langsung.
     * Tanpa tes ini, cabang @if($mode === 'edit' && ! $invoice->isEditable())
     * bisa gagal dikompilasi (aturan global 13/14) tanpa terlihat oleh suite.
     */
    /** @test */
    public function locked_invoice_edit_page_shows_the_correction_reason_field_to_superadmin(): void
    {
        $inv = $this->invoice();
        $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 300000]);
        $inv->recalcTotals();
        $this->assertFalse($inv->fresh()->isEditable());

        $this->actingAs($this->user('superadmin'))
            ->get(route('service.invoice.edit', $inv->id))
            ->assertOk()
            ->assertSee('name="correction_reason"', false)
            ->assertSee('Instalasi OJS');
    }

    /**
     * Risiko 3: invoice_no ADA di $fillable model (dibutuhkan penomoran saat
     * create), tapi ServiceInvoiceForm::rules() sengaja tidak menyebutkannya.
     * Kalau suatu saat seseorang menambah 'invoice_no' ke rules() atau ke array
     * literal di update(), tes ini merah.
     */
    /** @test */
    public function invoice_no_cannot_be_changed_through_the_update_form(): void
    {
        $inv = $this->invoice();
        $originalNo = $inv->invoice_no;

        $this->actingAs($this->user('manager'))
            ->put(route('service.invoice.update', $inv->id), $this->payload(['invoice_no' => 'INV-JS-HACK-0001']))
            ->assertRedirect(route('service.invoice.show', $inv->id));

        $this->assertSame($originalNo, $inv->fresh()->invoice_no);
    }

    /**
     * Risiko 2: subtotal/total/paid_total/remaining/payment_status SENGAJA tidak
     * fillable — satu-satunya penulisnya adalah recalcTotals(). Kirim nilai palsu
     * lewat form dan pastikan angka yang tersimpan tetap yang dihitung ulang dari
     * item & pembayaran ASLI, bukan dari payload.
     */
    /** @test */
    public function update_cannot_inject_paid_total_remaining_or_payment_status(): void
    {
        $inv = $this->invoice();
        $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 300000]);
        $inv->recalcTotals();
        $this->assertEquals(300000, $inv->fresh()->paid_total);

        $superadmin = $this->user('superadmin');
        $this->actingAs($superadmin)->put(
            route('service.invoice.update', $inv->id),
            $this->payload([
                'correction_reason' => 'uji injeksi kolom turunan',
                'paid_total'        => '99999999',
                'remaining'         => '0',
                'payment_status'    => 'lunas',
            ])
        )->assertRedirect(route('service.invoice.show', $inv->id));

        $inv->refresh();
        // Total baru (900000) dikurangi pembayaran ASLI (300000) = 600000 —
        // bukan angka manapun yang dikirim lewat payload di atas.
        $this->assertEquals(900000, $inv->total);
        $this->assertEquals(300000, $inv->paid_total);
        $this->assertEquals(600000, $inv->remaining);
        $this->assertSame('dp', $inv->payment_status);
    }

    /**
     * Risiko 4: syncItems() menghapus & membuat ulang SEMUA baris item saat
     * koreksi superadmin. Pembayaran ada di tabel terpisah (tb_service_invoice_payments)
     * dan tidak boleh ikut tersentuh oleh operasi itu.
     */
    /** @test */
    public function superadmin_correction_leaves_existing_payments_untouched(): void
    {
        $inv = $this->invoice();
        $payment = $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 300000]);
        $inv->recalcTotals();

        $this->actingAs($this->user('superadmin'))->put(
            route('service.invoice.update', $inv->id),
            $this->payload(['correction_reason' => 'koreksi item, pembayaran tidak berubah'])
        )->assertRedirect(route('service.invoice.show', $inv->id));

        $inv->refresh();
        $this->assertCount(1, $inv->payments);
        $this->assertSame($payment->id, $inv->payments->first()->id);
        $this->assertEquals(300000, $inv->payments->first()->amount);
        $this->assertEquals(300000, $inv->paid_total);
    }
}
