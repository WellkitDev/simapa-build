<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Deteksi dini Google Drive.
 *
 * Seluruh unggahan berkas bergantung pada satu refresh token. Kalau token itu dicabut
 * atau kedaluwarsa, tak ada yang meledak: unggahan hanya diam-diam berhenti bekerja
 * dan sebabnya hanya muncul di laravel.log. Yang pertama tahu selama ini adalah
 * pengguna yang gagal mengunggah.
 *
 * Perintah ini dijalankan terjadwal supaya token yang mati ketahuan sebelum ada yang
 * mengeluh.
 */
class DriveKesehatanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    /** @test */
    public function drive_sehat_tidak_membangunkan_siapa_pun(): void
    {
        $super = $this->superadmin();
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('cekKesehatan')->andReturn(true);
        });

        $this->artisan('drive:cek-kesehatan')->assertExitCode(0);

        $this->assertSame(0, $super->fresh()->notifications()->count(),
            'Notifikasi saat semuanya baik-baik saja hanya melatih orang mengabaikannya.');
    }

    /** @test */
    public function drive_bermasalah_memberi_tahu_superadmin_dan_keluar_gagal(): void
    {
        $super = $this->superadmin();
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('cekKesehatan')
                ->andThrow(new \RuntimeException('Refresh token is missing in config.'));
        });

        $this->artisan('drive:cek-kesehatan')->assertExitCode(1);

        $notif = $super->fresh()->notifications()->first();
        $this->assertNotNull($notif, 'Token mati yang tak diberitahukan sama saja tak terdeteksi.');
        $this->assertStringContainsString('Refresh token', (string) $notif->data['message']);
    }
}
