<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingExportTest extends TestCase
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
    public function journal_csv_download(): void
    {
        CashEntry::create(['tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 500000, 'keterangan' => 'Masuk Juni', 'source' => 'manual']);
        $res = $this->actingAs($this->user('accounting'))->get(route('accounting.journal.export.csv', ['year' => 2026, 'month' => 6]));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $body = $res->streamedContent();
        $this->assertStringContainsString('Pemasukan', $body); // header kolom
        $this->assertStringContainsString('Masuk Juni', $body); // baris entri
    }

    /** @test */
    public function journal_pdf_download(): void
    {
        $res = $this->actingAs($this->user('accounting'))->get(route('accounting.journal.export.pdf', ['year' => 2026]));
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $res->headers->get('Content-Type'));
    }

    /** @test */
    public function marketing_cannot_export(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.journal.export.csv'))->assertForbidden();
    }

    /** @test */
    public function recap_csv_download(): void
    {
        $res = $this->actingAs($this->user('accounting'))->get(route('accounting.recap.export.csv', ['year' => 2026]));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('YTD', $res->streamedContent());
    }

    /** @test */
    public function recap_pdf_download(): void
    {
        $res = $this->actingAs($this->user('accounting'))->get(route('accounting.recap.export.pdf', ['year' => 2026]));
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $res->headers->get('Content-Type'));
    }
}
