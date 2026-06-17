<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tagihan;
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
            'type'         => 'buku',
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
}
