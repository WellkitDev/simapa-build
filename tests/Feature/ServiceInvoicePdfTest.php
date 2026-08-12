<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use App\Support\ServiceInvoicePdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoicePdfTest extends TestCase
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
        $inv = ServiceInvoice::factory()->create(['discount' => 100000]);
        $inv->items()->create(['name' => 'Setup Lengkap Jurnal', 'qty' => 1, 'unit_price' => 2500000, 'subtotal' => 2500000]);
        $inv->payments()->create(['paid_at' => '2026-08-12', 'type' => 'dp', 'amount' => 1000000]);
        $inv->recalcTotals();

        return $inv->refresh();
    }

    /** @test */
    public function pdf_data_carries_invoice_items_and_payments(): void
    {
        $data = ServiceInvoicePdfData::for($this->invoice());

        $this->assertArrayHasKey('invoice', $data);
        $this->assertCount(1, $data['invoice']->items);
        $this->assertCount(1, $data['invoice']->payments);
        $this->assertEquals(2400000, $data['invoice']->total);
        $this->assertEquals(1400000, $data['invoice']->remaining);
    }

    /** @test */
    public function pdf_route_streams_a_pdf(): void
    {
        $inv = $this->invoice();

        $response = $this->actingAs($this->user('manager'))->get(route('service.invoice.pdf', $inv->id));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    /**
     * pdf_route_streams_a_pdf only asserts a 200 + content-type — a template that
     * renders a blank page (verified during implementation: `<html><body>blank</body></html>`
     * still made all four plan tests pass) would slip through undetected.
     *
     * A first attempt at pinning content compared raw PDF bytes between two different
     * invoices, expecting different content to yield different bytes. That was WRONG:
     * dompdf embeds a second-precision `/CreationDate` + `/ModDate` in every render, so
     * even the SAME invoice rendered twice back-to-back never produces identical bytes
     * (verified empirically) — the byte-diff assertion passed even when the controller
     * was sabotaged to always load a hardcoded invoice regardless of {id}, because the
     * timestamp alone guaranteed a diff. Plain invoice_no text is also not found inside
     * the raw PDF bytes (dompdf compresses/subsets fonts).
     *
     * The reliable, deterministic signal instead: `stream()` (barryvdh/laravel-dompdf
     * PDF.php) builds Content-Disposition from the SAME $invoice variable passed to the
     * view, so the filename cannot diverge from what was rendered. Confirmed this catches
     * a controller that ignores {id} (see falsification note in the task report).
     *
     * @test
     */
    public function pdf_route_filename_matches_the_requested_invoice(): void
    {
        $user = $this->user('manager');

        $a = ServiceInvoice::factory()->create(['discount' => 0]);
        $a->items()->create(['name' => 'Setup Lengkap Jurnal', 'qty' => 1, 'unit_price' => 2500000, 'subtotal' => 2500000]);
        $a->recalcTotals();

        $b = ServiceInvoice::factory()->create(['discount' => 0]);
        $b->items()->create(['name' => 'Layanan Turnitin', 'qty' => 1, 'unit_price' => 150000, 'subtotal' => 150000]);
        $b->recalcTotals();

        $a->refresh();
        $b->refresh();
        $this->assertNotSame($a->invoice_no, $b->invoice_no);

        $respA = $this->actingAs($user)->get(route('service.invoice.pdf', $a->id));
        $respB = $this->actingAs($user)->get(route('service.invoice.pdf', $b->id));

        $respA->assertOk();
        $respB->assertOk();
        $this->assertStringContainsString($a->invoice_no, $respA->headers->get('Content-Disposition'));
        $this->assertStringContainsString($b->invoice_no, $respB->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString($b->invoice_no, $respA->headers->get('Content-Disposition'));
    }

    /**
     * dompdf output being unsearchable for plain text (see test above) means the ONLY way
     * to pin that the view actually prints the invoice's own fields — rather than merely
     * compiling without error — is to render the Blade view directly (bypassing dompdf)
     * and assert on the resulting HTML.
     *
     * Asserting on invoice_no against the FULL html was itself a near-miss: `<title>Invoice
     * Layanan {{ $invoice->invoice_no }}</title>` sits in `<head>`, so a version of the
     * template with the visible `#{{ $invoice->invoice_no }}` row deleted from the body
     * still made a whole-document assertStringContainsString pass (verified during
     * implementation). Checking only the `<body>` slice closes that gap.
     *
     * @test
     */
    public function invoice_pdf_view_prints_the_actual_invoice_fields(): void
    {
        $inv = $this->invoice();

        $html = view('services.invoices.invoice_pdf', ServiceInvoicePdfData::for($inv))->render();
        $body = substr($html, strpos($html, '<body>'));

        $this->assertStringContainsString($inv->invoice_no, $body);
        $this->assertStringContainsString('Setup Lengkap Jurnal', $body);
        $this->assertStringContainsString(number_format((float) $inv->total, 0, ',', '.'), $body);
        $this->assertStringContainsString(strtoupper($inv->client_name), strtoupper($body));
    }

    /**
     * internal_note is the operator's private note (visible on the show.blade.php screen
     * behind an explicit "tidak tercetak" label) and must NEVER reach a document a client
     * can receive. Rendering the Blade view directly avoids false confidence from dompdf's
     * compressed/font-subsetted byte stream, where a plain-text search would misleadingly
     * report "absent" even if the template did print it.
     *
     * @test
     */
    public function internal_note_never_appears_in_pdf_output(): void
    {
        $inv = $this->invoice();
        $inv->update(['internal_note' => 'CATATAN-RAHASIA-OPERATOR-JANGAN-CETAK']);

        $html = view('services.invoices.invoice_pdf', ServiceInvoicePdfData::for($inv->fresh()))->render();

        $this->assertStringNotContainsString('CATATAN-RAHASIA-OPERATOR-JANGAN-CETAK', $html);
    }

    /** @test */
    public function pdf_renders_when_invoice_has_zero_payments(): void
    {
        $inv = ServiceInvoice::factory()->create(['discount' => 0]);
        $inv->items()->create(['name' => 'Review Naskah', 'qty' => 1, 'unit_price' => 500000, 'subtotal' => 500000]);
        $inv->recalcTotals();

        $this->assertCount(0, $inv->refresh()->payments);

        $this->actingAs($this->user('manager'))
            ->get(route('service.invoice.pdf', $inv->id))
            ->assertOk();
    }

    /** @test */
    public function pdf_renders_for_an_invoice_whose_client_and_catalog_were_deleted(): void
    {
        $inv = $this->invoice();
        $inv->update(['service_client_id' => null]);   // klien sudah dihapus

        $this->actingAs($this->user('manager'))
            ->get(route('service.invoice.pdf', $inv->id))
            ->assertOk();
    }

    /**
     * Plan rule 10: access tests must include superadmin, not just manager. Gate::before
     * (AuthServiceProvider) makes superadmin bypass Spatie's permission check entirely,
     * so it takes a genuinely different code path through EnforcePermission than manager
     * does — a manager-only test would never see a bug on that path. Concretely for THIS
     * route: EnforcePermission's fail-closed check is `$permission === null || ! can()` —
     * short-circuiting on `$permission === null` means an unmapped route denies even
     * superadmin (no 500 from Gate::before masking a missing permission mapping here),
     * but that must be verified, not assumed.
     *
     * @test
     */
    public function superadmin_can_download(): void
    {
        $inv = $this->invoice();

        $this->actingAs($this->user('superadmin'))
            ->get(route('service.invoice.pdf', $inv->id))
            ->assertOk();
    }

    /** @test */
    public function other_roles_cannot_download(): void
    {
        $inv = $this->invoice();

        foreach (['admin', 'marketing', 'production'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('service.invoice.pdf', $inv->id))
                ->assertForbidden();
        }
    }
}
