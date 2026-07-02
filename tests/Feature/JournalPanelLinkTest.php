<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Journal;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class JournalPanelLinkTest extends TestCase
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

    private function title(): Title
    {
        return Title::create(['title' => 'Judul Panel', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    /** @test */
    public function journal_option_from_directory_snapshots_journal_fields(): void
    {
        $mgr = $this->user('manager');
        $journal = Journal::create(['nama' => 'Jurnal Direktori', 'link' => 'https://jd.test', 'apc_reguler' => 'Rp 2.000.000', 'created_by' => $mgr->id]);
        $title = $this->title();

        $this->actingAs($mgr)->put(route('title.info.update', $title->id), [
            'journal_options' => [['journal_id' => $journal->id]],
        ])->assertRedirect();

        $opt = $title->journalOptions()->first();
        $this->assertNotNull($opt);
        $this->assertSame($journal->id, $opt->journal_id);
        $this->assertSame('Jurnal Direktori', $opt->nama_jurnal);
        $this->assertSame('https://jd.test', $opt->link);
        $this->assertSame('Rp 2.000.000', $opt->apc);
    }

    /** @test */
    public function free_text_journal_option_still_works(): void
    {
        $mgr = $this->user('manager');
        $title = $this->title();

        $this->actingAs($mgr)->put(route('title.info.update', $title->id), [
            'journal_options' => [['nama_jurnal' => 'Jurnal Manual', 'link' => 'https://m.test', 'apc' => 'Gratis']],
        ])->assertRedirect();

        $opt = $title->journalOptions()->first();
        $this->assertNull($opt->journal_id);
        $this->assertSame('Jurnal Manual', $opt->nama_jurnal);
    }

    /** @test */
    public function clearing_target_terbit_stores_null_not_today(): void
    {
        $mgr = $this->user('manager');
        $title = $this->title();
        $title->update(['target_terbit' => '2026-10-01']);

        $this->actingAs($mgr)->put(route('title.info.update', $title->id), ['target_terbit' => ''])->assertRedirect();

        $this->assertNull($title->fresh()->target_terbit);
    }

    /** @test */
    public function detail_links_directory_journal_option(): void
    {
        $mgr = $this->user('manager');
        $journal = Journal::create(['nama' => 'Jurnal Tautan', 'created_by' => $mgr->id]);
        $title = $this->title();
        $title->journalOptions()->create(['journal_id' => $journal->id, 'nama_jurnal' => 'Jurnal Tautan', 'urutan' => 0]);

        $this->actingAs($mgr)->get(route('title.show', $title->id))
            ->assertOk()->assertSee(route('journal.show', $journal->id))->assertSee('Jurnal Tautan');
    }
}
