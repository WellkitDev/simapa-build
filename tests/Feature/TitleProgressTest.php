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
            'event'             => 'status_advanced',
            'from_value'        => 'Menunggu Proses',
            'to_value'          => 'Editing',
            'is_correction'     => false,
        ]);
    }

    /** @test */
    public function manager_jump_requires_note_then_succeeds_and_flags_review(): void
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

        // Lompat tanpa catatan → ditolak, status tak berubah.
        $this->post(route('title.progress.update', $progress->id), ['status' => 'terbit', 'note' => ''])
            ->assertSessionHasErrors('note');
        $this->assertDatabaseHas('tb_title_progress', ['id' => $progress->id, 'status' => 'menunggu_proses']);

        // Lompat dengan catatan → berhasil, ditandai perlu ditinjau.
        $this->post(route('title.progress.update', $progress->id), ['status' => 'terbit', 'note' => 'lompat dengan alasan'])
            ->assertRedirect();
        $this->assertDatabaseHas('tb_title_progress', [
            'id' => $progress->id, 'status' => 'terbit', 'needs_review' => true,
        ]);
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
            'event'         => 'status_corrected',
            'to_value'      => 'Editing',
            'is_correction' => true,
        ]);
    }

    /** @test */
    public function title_progress_is_created_when_order_is_stored(): void
    {
        $this->actingAs($this->marketing);

        $payload = [
            'type'             => 'bk_mandiri',
            'title_id'         => 'Buku Tes Integrasi',
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
            'title_id'         => 'Artikel Tes Integrasi',
            'scope_id'         => '', // form selalu mengirim field ini (boleh kosong)
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

    /** @test */
    public function new_book_order_of_existing_title_inherits_group_status(): void
    {
        // Sudah ada order judul sama yang sedang di stage 'layout' (tertaut ke Title —
        // order-nyata pasca-2a selalu punya title_id, dan grup manuskrip berbasis title_id).
        $warisan = \App\Models\Title::create([
            'title' => 'Buku Warisan', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
        ]);
        $existing = \App\Models\OrderDetail::factory()->create([
            'type' => 'bk_mandiri', 'title' => 'Buku Warisan', 'title_id' => $warisan->id,
        ]);
        TitleProgress::create([
            'order_detail_id' => $existing->id,
            'status'          => 'layout',
            'assigned_role'   => 'production',
            'started_at'      => now(),
        ]);

        $this->actingAs($this->marketing);

        $payload = [
            'type'             => 'bk_mandiri',
            'title_id'         => 'Buku Warisan', // judul sama → grup sama
            'naskah_type'      => 'mandiri',
            'publication_type' => 'regular',
            'issued_at'        => now()->toDateString(),
            'cost_amount'      => 1000000,
            'contact_phone'    => '08123456789',
            'contact_email'    => 'warisan@example.com',
            'authors'          => [
                [
                    'name'        => 'Penulis Warisan',
                    'email'       => 'penulis-warisan@example.com',
                    'phone'       => '0812',
                    'affiliation' => 'UI',
                    'position'    => 1,
                ],
            ],
        ];

        $this->post(route('order.book.store'), $payload);

        $new = \App\Models\OrderDetail::where('title', 'Buku Warisan')
            ->where('id', '!=', $existing->id)
            ->firstOrFail();

        // Order baru mewarisi status grup, bukan reset ke 'menunggu_proses'.
        $this->assertEquals('layout', $new->titleProgress->status);
    }
}
