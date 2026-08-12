<?php

namespace Tests\Feature;

use App\Jobs\SendServiceInvoiceJob;
use App\Mail\ServiceInvoiceMail;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoiceMailTest extends TestCase
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

    private function invoice(array $override = []): ServiceInvoice
    {
        $inv = ServiceInvoice::factory()->create($override);
        $inv->items()->create(['name' => 'Setup Lengkap Jurnal', 'qty' => 1, 'unit_price' => 2500000, 'subtotal' => 2500000]);
        $inv->recalcTotals();

        return $inv->refresh();
    }

    /** @test */
    public function send_dispatches_the_job(): void
    {
        Queue::fake();
        $inv = $this->invoice();

        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.send', $inv->id))
            ->assertRedirect();

        Queue::assertPushed(SendServiceInvoiceJob::class);
    }

    /** @test */
    public function send_is_refused_when_the_client_has_no_email(): void
    {
        Queue::fake();
        $inv = $this->invoice(['client_email' => null]);

        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.send', $inv->id))
            ->assertRedirect();

        Queue::assertNothingPushed();
        $this->assertNull($inv->fresh()->sent_at);
    }

    /** @test */
    public function job_mails_the_invoice_and_stamps_the_send(): void
    {
        Mail::fake();
        $inv = $this->invoice();

        $drive = \Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('getOrCreateFolderByPath')->andReturn('folder-id');
        $drive->shouldReceive('uploadFile')->andReturn(['url' => 'https://drive.example/x']);

        (new SendServiceInvoiceJob($inv->id))->handle($drive);

        Mail::assertSent(ServiceInvoiceMail::class, fn ($m) => $m->hasTo($inv->client_email));

        $inv->refresh();
        $this->assertNotNull($inv->sent_at);
        $this->assertSame(1, $inv->sent_count);
        $this->assertSame('https://drive.example/x', $inv->pdf_drive_url);
        $this->assertSame('emailed', $inv->logs->first()->event);
    }

    /** @test */
    public function drive_failure_does_not_stop_the_email(): void
    {
        Mail::fake();
        $inv = $this->invoice();

        $drive = \Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('getOrCreateFolderByPath')->andThrow(new \RuntimeException('Drive down'));

        (new SendServiceInvoiceJob($inv->id))->handle($drive);

        Mail::assertSent(ServiceInvoiceMail::class);

        $inv->refresh();
        $this->assertNotNull($inv->sent_at, 'Email terkirim, jadi pengirimannya harus tetap tercatat.');
        $this->assertNull($inv->pdf_drive_url);
    }

    /** @test */
    public function mailable_subject_uses_the_real_invoice_number(): void
    {
        $inv  = $this->invoice();
        $mail = new ServiceInvoiceMail($inv, 'PDFBYTES');

        // Jebakan yang TIDAK diwarisi: InvoiceMail lama memakai $invoice->inv_no
        // yang tidak ada, sehingga subjeknya terkirim tanpa nomor.
        $this->assertStringContainsString($inv->invoice_no, $mail->envelope()->subject);
    }

    /** @test */
    public function other_roles_cannot_send(): void
    {
        Queue::fake();
        $inv = $this->invoice();

        // Non-GET ditolak dengan redirect + flash, bukan 403 (EnforcePermission::deny),
        // jadi bukti sebenarnya ada di antrean yang tetap kosong.
        foreach (['admin', 'marketing', 'production'] as $role) {
            $this->actingAs($this->user($role))
                ->post(route('service.invoice.send', $inv->id))
                ->assertRedirect();
        }

        Queue::assertNothingPushed();
        $this->assertNull($inv->fresh()->sent_at);
    }

    /**
     * Risiko 3 (brief tugas): Mail::fake() MENCEGAT sebelum view-nya dirender, jadi
     * tes lain di berkas ini tak pernah membuktikan Blade-nya benar-benar kompilasi.
     * assertHasAttachedData() memanggil renderForAssertions() di baliknya, jadi
     * assersi ini SEKALIGUS memaksa view-nya dirender sungguhan (Aturan global 13)
     * DAN membuktikan byte PDF yang dilampirkan persis sama dengan yang diberikan
     * ke konstruktor (Risiko 4) — bukan string kosong atau placeholder.
     */
    /** @test */
    public function mailable_renders_the_view_and_attaches_the_given_pdf_bytes(): void
    {
        $inv = $this->invoice();
        $pdfBytes = '%PDF-1.4 isi-pdf-uji-coba-bukan-string-kosong';
        $mail = new ServiceInvoiceMail($inv, $pdfBytes);

        $mail->assertHasAttachedData(
            $pdfBytes,
            'Invoice_Layanan_' . $inv->invoice_no . '.pdf',
            ['mime' => 'application/pdf']
        );

        // Bukti tambahan bahwa view-nya benar-benar merender data invoice, bukan cuma
        // "tidak error": nomor invoice dan nama item harus terlihat di HTML yang jadi.
        $mail->assertSeeInHtml($inv->invoice_no);
        $mail->assertSeeInHtml('Setup Lengkap Jurnal');
    }

    /**
     * Risiko 2: sent_count TIDAK di-reset — ia bertambah setiap kali job jalan. Tes
     * lain hanya mengirim sekali; kalau implementasinya diam-diam menulis literal 1
     * alih-alih $invoice->sent_count + 1, tes itu tidak akan pernah menyadarinya.
     */
    /** @test */
    public function sending_twice_increments_sent_count_instead_of_resetting_it(): void
    {
        Mail::fake();
        $inv = $this->invoice();

        $drive = \Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('getOrCreateFolderByPath')->andReturn('folder-id');
        $drive->shouldReceive('uploadFile')->andReturn(['url' => 'https://drive.example/x']);

        (new SendServiceInvoiceJob($inv->id))->handle($drive);
        (new SendServiceInvoiceJob($inv->id))->handle($drive);

        $inv->refresh();
        $this->assertSame(2, $inv->sent_count);
        $this->assertSame(2, $inv->logs()->where('event', 'emailed')->count());
    }

    /**
     * Risiko 5: hook failed() milik queue harus tetap bisa menulis baris log
     * 'email_failed' walau job-nya gagal total sebelum sempat menyentuh apa pun
     * lain — $this->invoiceId harus tetap tersedia di sana.
     */
    /** @test */
    public function failed_hook_writes_an_email_failed_log_row(): void
    {
        $inv = $this->invoice();

        (new SendServiceInvoiceJob($inv->id))->failed(new \RuntimeException('SMTP timeout sewaktu uji'));

        $log = $inv->logs()->where('event', 'email_failed')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('SMTP timeout sewaktu uji', $log->note);
    }
}
