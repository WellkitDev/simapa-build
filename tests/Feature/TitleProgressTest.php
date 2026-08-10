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

    // Perpindahan tahap & koreksi kini milik modul Penugasan Naskah — diuji di
    // NaskahDetailTest (HTTP) dan TitleProgressServiceTest (aturan). Yang tersisa di
    // sini: pembuatan TitleProgress saat order disimpan.

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
