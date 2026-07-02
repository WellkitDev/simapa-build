<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class JournalSubmissionPagesTest extends TestCase
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
    public function detail_lists_submissions_and_manager_sees_add_button(): void
    {
        $mgr = $this->user('manager');
        $j = Journal::create(['nama' => 'Jurnal Z', 'created_by' => $mgr->id]);
        $title = Title::create(['title' => 'Artikel Tercatat', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        JournalSubmission::create(['journal_id' => $j->id, 'title_id' => $title->id, 'status' => 'loa']);

        $this->actingAs($mgr)->get(route('journal.show', $j->id))
            ->assertOk()->assertSee('Artikel Tercatat')->assertSee('Tambah Artikel Submit');
    }

    /** @test */
    public function marketing_sees_list_without_manage_buttons(): void
    {
        $mgr = $this->user('manager');
        $j = Journal::create(['nama' => 'Jurnal Z', 'created_by' => $mgr->id]);
        JournalSubmission::create(['journal_id' => $j->id, 'status' => 'submitted']);

        $this->actingAs($this->user('marketing'))->get(route('journal.show', $j->id))
            ->assertOk()->assertDontSee('Tambah Artikel Submit');
    }
}
