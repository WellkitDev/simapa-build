<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Scope;
use App\Models\Journal;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class JournalControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
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

    private function payload(array $over = []): array
    {
        return array_merge([
            'nama' => 'Jurnal Riset Nusantara',
            'akreditasi' => 'SINTA 2',
            'scope_id' => 'Teknik Informatika',
            'apc_reguler' => 'Rp 3.000.000',
            'apc_fastrack' => 'Rp 5.000.000',
            'link' => 'https://jrn.test',
            'kontak_wa' => '0812',
            'kontak_email' => 'editor@jrn.test',
            'terbitan_bulan' => [1, 6],
            'catatan' => 'catatan',
        ], $over);
    }

    /** @test */
    public function manager_creates_journal_with_new_scope_and_months(): void
    {
        $this->actingAs($this->user('manager'))->post(route('journal.store'), $this->payload())->assertRedirect();

        $j = Journal::where('nama', 'Jurnal Riset Nusantara')->first();
        $this->assertNotNull($j);
        $this->assertSame([1, 6], $j->terbitan_bulan);
        $this->assertSame('Teknik Informatika', $j->scope->scope);
        $this->assertSame(1, Scope::where('scope', 'Teknik Informatika')->count());
        $this->assertSame(['Jan', 'Jun'], $j->terbitanLabels());
    }

    /** @test */
    public function manager_updates_and_deletes_journal(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->post(route('journal.store'), $this->payload())->assertRedirect();
        $j = Journal::first();

        $this->actingAs($mgr)->put(route('journal.update', $j->id), $this->payload(['nama' => 'Jurnal Baru', 'terbitan_bulan' => [3]]))->assertRedirect();
        $this->assertSame('Jurnal Baru', $j->fresh()->nama);
        $this->assertSame([3], $j->fresh()->terbitan_bulan);

        $this->actingAs($mgr)->delete(route('journal.destroy', $j->id))->assertRedirect();
        $this->assertSame(0, Journal::count());
    }

    /** @test */
    public function marketing_can_view_but_not_manage(): void
    {
        $j = Journal::create(['nama' => 'Lihat Saja', 'created_by' => $this->user('manager')->id]);
        $mkt = $this->user('marketing');

        $this->actingAs($mkt)->get(route('journal.index'))->assertOk()->assertSee('Lihat Saja');
        $this->actingAs($mkt)->get(route('journal.show', $j->id))->assertOk();
        $this->actingAs($mkt)->get(route('journal.create'))->assertForbidden();
        $this->actingAs($mkt)->post(route('journal.store'), $this->payload())->assertRedirect()->assertSessionHas('error');
    }
}
