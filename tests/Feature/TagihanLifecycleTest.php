<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tagihan;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TagihanLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        // OrderBookController constructor injects GoogleDriveService — avoid real API calls.
        $this->mock(GoogleDriveService::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function validPayload(array $override = []): array
    {
        return array_merge([
            'client_name'  => 'PT Klien',
            'client_email' => 'klien@contoh.id',
            'client_phone' => '08123456789',
            'title'        => 'Naskah Tagihan',
            'type'         => 'bk_mandiri',
            'author_names' => 'Budi, Sari',
            'description'  => 'Jasa penerbitan',
            'amount'       => 2000000,
            'due_at'       => now()->addDays(14)->toDateString(),
            'note'         => 'Catatan',
        ], $override);
    }

    /** @test */
    public function marketing_creates_tagihan_as_diajukan_with_log(): void
    {
        $me = $this->user('marketing');
        $this->actingAs($me);

        $this->post(route('tagihan.store'), $this->validPayload())->assertRedirect();

        $this->assertDatabaseHas('tb_tagihan', [
            'created_by' => $me->id, 'title' => 'Naskah Tagihan', 'status' => 'diajukan',
        ]);
        $tagihan = Tagihan::where('created_by', $me->id)->first();
        $this->assertDatabaseHas('tb_tagihan_logs', ['tagihan_id' => $tagihan->id, 'to_status' => 'diajukan']);
        $this->assertStringStartsWith('TAG-', $tagihan->tagihan_no);
    }

    /** @test */
    public function marketing_only_sees_own_tagihan_in_index(): void
    {
        $me  = $this->user('marketing');
        $other = $this->user('marketing');
        Tagihan::factory()->create(['created_by' => $me->id, 'title' => 'MILIK SAYA']);
        Tagihan::factory()->create(['created_by' => $other->id, 'title' => 'MILIK ORANG LAIN']);

        $this->actingAs($me);
        $this->get(route('tagihan.index'))
            ->assertOk()
            ->assertSee('MILIK SAYA')
            ->assertDontSee('MILIK ORANG LAIN');
    }

    /** @test */
    public function manager_sees_all_tagihan(): void
    {
        $mkt = $this->user('marketing');
        Tagihan::factory()->create(['created_by' => $mkt->id, 'title' => 'TAGIHAN MARKETING']);

        $this->actingAs($this->user('manager'));
        $this->get(route('tagihan.index'))->assertOk()->assertSee('TAGIHAN MARKETING');
    }

    /** @test */
    public function production_cannot_access_or_create_tagihan(): void
    {
        $this->actingAs($this->user('production'));
        $this->get(route('tagihan.index'))->assertStatus(403);
        $this->post(route('tagihan.store'), $this->validPayload())->assertStatus(403);
    }

    /** @test */
    public function superadmin_approves_tagihan_sets_disetujui_with_log(): void
    {
        $t = Tagihan::factory()->create(['status' => 'diajukan']);
        $this->actingAs($this->user('superadmin'));

        $this->post(route('tagihan.approve', $t->id))->assertRedirect();

        $this->assertDatabaseHas('tb_tagihan', ['id' => $t->id, 'status' => 'disetujui']);
        $this->assertDatabaseHas('tb_tagihan_logs', ['tagihan_id' => $t->id, 'to_status' => 'disetujui']);
        $this->assertNotNull($t->fresh()->approved_at);
    }

    /** @test */
    public function marketing_cannot_approve_tagihan(): void
    {
        $t = Tagihan::factory()->create(['status' => 'diajukan']);
        $this->actingAs($this->user('marketing'));
        $this->post(route('tagihan.approve', $t->id))->assertStatus(403);
    }

    /** @test */
    public function superadmin_rejects_with_note_then_owner_resubmits(): void
    {
        $owner = $this->user('marketing');
        $t = Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'diajukan']);

        $this->actingAs($this->user('superadmin'));
        $this->post(route('tagihan.reject', $t->id), ['note' => 'Nominal salah'])->assertRedirect();
        $this->assertDatabaseHas('tb_tagihan', ['id' => $t->id, 'status' => 'ditolak', 'reject_note' => 'Nominal salah']);

        // owner edit & ajukan ulang
        $this->actingAs($owner);
        $this->put(route('tagihan.update', $t->id), $this->validPayload(['amount' => 3000000]))->assertRedirect();
        $this->assertDatabaseHas('tb_tagihan', ['id' => $t->id, 'status' => 'diajukan', 'amount' => 3000000]);
    }

    /** @test */
    public function owner_can_cancel_tagihan(): void
    {
        $owner = $this->user('marketing');
        $t = Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'disetujui']);

        $this->actingAs($owner);
        $this->post(route('tagihan.cancel', $t->id))->assertRedirect();
        $this->assertDatabaseHas('tb_tagihan', ['id' => $t->id, 'status' => 'dibatalkan']);
    }

    /** @test */
    public function cannot_edit_tagihan_after_approved(): void
    {
        $owner = $this->user('marketing');
        $t = Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'disetujui']);
        $this->actingAs($owner);
        $this->get(route('tagihan.edit', $t->id))->assertStatus(403);
    }

    /** @test */
    public function pdf_downloadable_only_after_approved_by_owner(): void
    {
        $owner = $this->user('marketing');
        $diajukan = Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'diajukan']);
        $disetujui = Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'disetujui']);

        $this->actingAs($owner);
        $this->get(route('tagihan.pdf', $diajukan->id))->assertStatus(403);
        $resp = $this->get(route('tagihan.pdf', $disetujui->id))->assertOk();
        $this->assertEquals('application/pdf', $resp->headers->get('content-type'));
    }

    /** @test */
    public function buat_order_redirects_to_book_create_with_prefill_param(): void
    {
        $owner = $this->user('marketing');
        $t = Tagihan::factory()->create([
            'created_by' => $owner->id, 'status' => 'disetujui',
            'type' => 'buku', 'title' => 'JUDUL TAGIHAN', 'client_email' => 'a@b.id',
        ]);

        $this->actingAs($owner);
        $this->get(route('tagihan.buatOrder', $t->id))
            ->assertRedirect(route('order.book.create', ['from_tagihan' => $t->id]));

        $this->get(route('order.book.create', ['from_tagihan' => $t->id]))
            ->assertOk()
            ->assertSee('JUDUL TAGIHAN', false)
            ->assertSee('a@b.id', false);
    }

    /** @test */
    public function buat_order_blocked_when_not_disetujui(): void
    {
        $owner = $this->user('marketing');
        $t = Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'diajukan']);
        $this->actingAs($owner);
        $this->get(route('tagihan.buatOrder', $t->id))->assertRedirect();
        $this->assertEquals('diajukan', $t->fresh()->status);
    }

    /** @test */
    public function approved_tagihan_not_counted_as_income_until_order(): void
    {
        $owner = $this->user('marketing');
        Tagihan::factory()->create(['created_by' => $owner->id, 'status' => 'disetujui', 'amount' => 5000000]);

        $svc = app(\App\Services\MarketingDashboardService::class)->forUser($owner);
        $this->assertEquals(0, $svc['pemasukan_tahun_ini']);
    }

    /** @test */
    public function creating_book_order_from_tagihan_marks_it_jadi_order(): void
    {
        $owner = $this->user('marketing');
        $t = \App\Models\Tagihan::factory()->create([
            'created_by' => $owner->id, 'status' => 'disetujui', 'type' => 'buku', 'title' => 'Dari Tagihan',
        ]);

        $payload = [
            'type' => 'bk_mandiri', 'title' => 'Dari Tagihan', 'naskah_type' => 'mandiri',
            'publication_type' => 'regular', 'issued_at' => now()->toDateString(), 'cost_amount' => 1500000,
            'contact_phone' => '0811', 'contact_email' => 'klien@x.id',
            'authors' => [['name' => 'Penulis', 'position' => 1]],
            'from_tagihan' => $t->id,
        ];

        $this->actingAs($owner);
        $this->post(route('order.book.store'), $payload)->assertRedirect();

        $t->refresh();
        $this->assertEquals('jadi_order', $t->status);
        $this->assertNotNull($t->order_id);
        $this->assertNotNull($t->order_code);
        $this->assertDatabaseHas('tb_tagihan_logs', ['tagihan_id' => $t->id, 'to_status' => 'jadi_order']);
    }

    /** @test */
    public function creating_journal_order_from_tagihan_marks_it_jadi_order(): void
    {
        $owner = $this->user('marketing');
        $t = \App\Models\Tagihan::factory()->create([
            'created_by' => $owner->id, 'status' => 'disetujui', 'type' => 'jurnal', 'title' => 'Dari Tagihan Jurnal',
        ]);

        $payload = [
            'type' => 'at_mandiri', 'title' => 'Dari Tagihan Jurnal', 'naskah_type' => 'mandiri',
            'indexation' => 'sinta 2',
            'publication_type' => 'regular', 'issued_at' => now()->toDateString(), 'cost_amount' => 2000000,
            'contact_phone' => '0812', 'contact_email' => 'jurnal@x.id',
            'scope_id' => '',
            'authors' => [['name' => 'Penulis Jurnal', 'position' => 1]],
            'from_tagihan' => $t->id,
        ];

        $this->actingAs($owner);
        $this->post(route('order.journal.store'), $payload)->assertRedirect();

        $t->refresh();
        $this->assertEquals('jadi_order', $t->status);
        $this->assertNotNull($t->order_id);
        $this->assertNotNull($t->order_code);
        $this->assertDatabaseHas('tb_tagihan_logs', ['tagihan_id' => $t->id, 'to_status' => 'jadi_order']);
    }
}
