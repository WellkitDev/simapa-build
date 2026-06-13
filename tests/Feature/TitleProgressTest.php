<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\TitleProgress;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock GoogleDriveService to avoid real API calls in tests
        $this->mock(GoogleDriveService::class);

        Role::create(['name' => 'marketing', 'guard_name' => 'web']);
        Role::create(['name' => 'manager',   'guard_name' => 'web']);
        Role::create(['name' => 'superadmin','guard_name' => 'web']);

        $this->marketing = User::factory()->create();
        $this->marketing->assignRole('marketing');
    }

    /** @test */
    public function title_progress_is_created_when_order_is_stored(): void
    {
        $this->actingAs($this->marketing);

        $payload = [
            'type'             => 'bk_mandiri',
            'title'            => 'Buku Tes Integrasi',
            'naskah_type'      => 'mandiri',
            'publication_type' => 'regular',
            'issued_at'        => now()->toDateString(),
            'cost_amount'      => 1000000,
            'contact_phone'    => '08123456789',
            'contact_email'    => 'test@example.com',
            'authors'          => [
                [
                    'name'        => 'Penulis Satu',
                    'email'       => 'penulis@example.com',
                    'phone'       => '0812',
                    'affiliation' => 'UI',
                    'position'    => 1,
                ],
            ],
        ];

        $this->post(route('order.book.store'), $payload);

        $this->assertDatabaseHas('tb_title_progress', [
            'status'        => 'menunggu_proses',
            'assigned_role' => 'marketing',
        ]);

        $this->assertEquals(1, TitleProgress::count());
    }
}
