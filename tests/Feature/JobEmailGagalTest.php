<?php

namespace Tests\Feature;

use App\Jobs\SendInvoiceJob;
use App\Jobs\SendRefundJob;
use App\Jobs\SendSalarySlipJob;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Job pengiriman email yang gagal harus terlihat oleh manusia.
 *
 * Ketiganya dulu tak punya failed(), jadi kegagalan berhenti sebagai baris di tabel
 * failed_jobs — tempat yang tak pernah dibuka siapa pun. Akibatnya invoice, refund,
 * atau slip gaji bisa "terkirim" menurut layar padahal tak pernah sampai, dan yang
 * pertama tahu adalah klien yang menagih.
 */
class JobEmailGagalTest extends TestCase
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

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u->fresh();
    }

    /**
     * @test
     * @dataProvider jobPengirim
     */
    public function job_pengirim_email_yang_gagal_memberi_tahu_superadmin(string $kelas): void
    {
        $super = $this->superadmin();

        // id yang tak ada sekalipun tak boleh membuat failed() ikut meledak: job yang
        // gagal DUA kali (sekali di handle, sekali di failed) hilang tanpa jejak.
        (new $kelas(999999))->failed(new \RuntimeException('SMTP menolak koneksi'));

        $this->assertSame(1, $super->fresh()->notifications()->count(),
            $kelas . '::failed() harus memberi tahu, bukan diam.');

        $isi = (string) $super->fresh()->notifications()->first()->data['message'];
        $this->assertStringContainsString('SMTP', $isi,
            'Sebab kegagalan harus ikut, kalau tidak notifikasinya tak bisa ditindaklanjuti.');
    }

    public static function jobPengirim(): array
    {
        return [
            'invoice'    => [SendInvoiceJob::class],
            'refund'     => [SendRefundJob::class],
            'slip gaji'  => [SendSalarySlipJob::class],
        ];
    }
}
