<?php

namespace Tests\Feature;

use App\Jobs\SendSalarySlipJob;
use App\Mail\SalarySlipMail;
use App\Models\SalarySlip;
use App\Models\User;
use App\Support\SalarySlipPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalarySlipMailTest extends TestCase
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

    /** @test */
    public function send_publishes_slip_and_dispatches_job(): void
    {
        Queue::fake();
        $slip = SalarySlip::factory()->create(['status' => 'draft']);

        $this->actingAs($this->user('accounting'))->post(route('salary.slip.send', $slip->id))->assertRedirect();

        $slip->refresh();
        $this->assertSame('terbit', $slip->status);
        $this->assertNotNull($slip->sent_at);
        Queue::assertPushed(SendSalarySlipJob::class);
    }

    /** @test */
    public function mailable_has_subject_and_pdf_attachment(): void
    {
        $slip = SalarySlip::factory()->create(['period_year' => 2026, 'period_month' => 7]);
        $data = SalarySlipPdfData::for($slip);
        $mail = new SalarySlipMail($slip, $data, 'PDFBYTES');

        $this->assertStringContainsString('Slip Gaji', $mail->envelope()->subject);
        $this->assertStringContainsString('Juli 2026', $mail->envelope()->subject);
        $this->assertCount(1, $mail->attachments());
    }

    /** @test */
    public function marketing_cannot_send(): void
    {
        $slip = SalarySlip::factory()->create();
        $this->actingAs($this->user('marketing'))->post(route('salary.slip.send', $slip->id))
            ->assertRedirect()->assertSessionHas('error');
    }
}
