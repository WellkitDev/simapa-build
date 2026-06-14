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
    public function manager_can_advance_status_to_next_stage(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $detail = \App\Models\OrderDetail::factory()->create(['type' => 'bk_mandiri']);
        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
            'assigned_role'   => 'marketing',
            'updated_by'      => $manager->id,
            'started_at'      => now(),
        ]);

        $this->actingAs($manager);

        $this->post(route('title.progress.update', $progress->id), [
            'status' => 'editing',
            'note'   => '',
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_title_progress', [
            'id'     => $progress->id,
            'status' => 'editing',
        ]);

        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $progress->id,
            'from_status'       => 'menunggu_proses',
            'to_status'         => 'editing',
            'is_correction'     => false,
        ]);
    }

    /** @test */
    public function manager_cannot_make_correction_jump(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $detail = \App\Models\OrderDetail::factory()->create(['type' => 'bk_mandiri']);
        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
            'assigned_role'   => 'marketing',
            'updated_by'      => $manager->id,
            'started_at'      => now(),
        ]);

        $this->actingAs($manager);

        $this->post(route('title.progress.update', $progress->id), [
            'status' => 'terbit',
            'note'   => '',
        ])->assertStatus(403);

        $this->assertDatabaseHas('tb_title_progress', ['id' => $progress->id, 'status' => 'menunggu_proses']);
    }

    /** @test */
    public function superadmin_can_make_correction_with_note(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('superadmin');

        $detail = \App\Models\OrderDetail::factory()->create(['type' => 'bk_mandiri']);
        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'isbn',
            'assigned_role'   => 'manager',
            'updated_by'      => $superadmin->id,
            'started_at'      => now(),
        ]);

        $this->actingAs($superadmin);

        $this->post(route('title.progress.update', $progress->id), [
            'status' => 'editing',
            'note'   => 'Koreksi karena ada revisi mendasar',
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_title_progress_logs', [
            'to_status'     => 'editing',
            'is_correction' => true,
        ]);
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

    /** @test */
    public function title_progress_is_created_when_journal_order_is_stored(): void
    {
        $this->actingAs($this->marketing);

        $payload = [
            'type'             => 'at_mandiri',
            'title'            => 'Artikel Tes Integrasi',
            'indexation'       => 'Scopus Q2',
            'naskah_type'      => 'mandiri',
            'publication_type' => 'regular',
            'issued_at'        => now()->toDateString(),
            'cost_amount'      => 2000000,
            'contact_phone'    => '08123456789',
            'contact_email'    => 'artikel@example.com',
            'authors'          => [
                [
                    'name'        => 'Penulis Artikel',
                    'email'       => 'penulis-artikel@example.com',
                    'phone'       => '0812',
                    'affiliation' => 'UI',
                    'position'    => 1,
                ],
            ],
        ];

        $this->post(route('order.journal.store'), $payload);

        $this->assertDatabaseHas('tb_title_progress', [
            'status'        => 'menunggu_proses',
            'assigned_role' => 'marketing',
        ]);

        $this->assertEquals(1, TitleProgress::count());
    }
}
