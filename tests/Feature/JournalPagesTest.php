<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Journal;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class JournalPagesTest extends TestCase
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

    /** @test */
    public function manager_opens_index_create_and_show(): void
    {
        $mgr = $this->user('manager');
        $j = Journal::create(['nama' => 'Jurnal X', 'terbitan_bulan' => [1, 6], 'created_by' => $mgr->id]);

        $this->actingAs($mgr)->get(route('journal.index'))->assertOk()->assertSee('Jurnal X');
        $this->actingAs($mgr)->get(route('journal.create'))->assertOk();
        $this->actingAs($mgr)->get(route('journal.show', $j->id))->assertOk()->assertSee('Jan')->assertSee('Jun');
    }

    /** @test */
    public function marketing_opens_index_but_not_create(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('journal.index'))->assertOk();
        $this->actingAs($this->user('marketing'))->get(route('journal.create'))->assertForbidden();
    }
}
