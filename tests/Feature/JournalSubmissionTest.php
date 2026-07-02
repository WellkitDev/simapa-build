<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class JournalSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function journal(): Journal
    {
        return Journal::create(['nama' => 'Jurnal Sub', 'created_by' => $this->user('manager')->id]);
    }

    /** @test */
    public function manager_adds_submission_with_encrypted_password(): void
    {
        $this->mock(GoogleDriveService::class);
        $j = $this->journal();
        $title = Title::create(['title' => 'Artikel A', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);

        $this->actingAs($this->user('manager'))->post(route('journal.submission.store', $j->id), [
            'title_id' => $title->id, 'tgl_submit' => '2026-08-01', 'status' => 'submitted',
            'ojs_akun' => 'akun1', 'ojs_password' => 'rahasia123', 'catatan' => 'c',
        ])->assertRedirect();

        $sub = JournalSubmission::first();
        $this->assertSame($title->id, $sub->title_id);
        $this->assertSame('submitted', $sub->status);
        $raw = DB::table('tb_journal_submissions')->where('id', $sub->id)->value('ojs_password');
        $this->assertNotSame('rahasia123', $raw);
        $this->assertSame('rahasia123', $sub->ojs_password);
    }

    /** @test */
    public function loa_file_uploads_to_drive(): void
    {
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'x', 'name' => 'loa.pdf', 'url' => 'https://drive.test/loa']);
        });
        $j = $this->journal();

        $this->actingAs($this->user('manager'))->post(route('journal.submission.store', $j->id), [
            'status' => 'submitted',
            'loa' => UploadedFile::fake()->create('loa.pdf', 40, 'application/pdf'),
        ])->assertRedirect();

        $this->assertSame('https://drive.test/loa', JournalSubmission::first()->loa_url);
    }

    /** @test */
    public function update_changes_status_and_keeps_password_when_blank(): void
    {
        $this->mock(GoogleDriveService::class);
        $j = $this->journal();
        $sub = JournalSubmission::create(['journal_id' => $j->id, 'status' => 'submitted', 'ojs_password' => 'lama']);

        $this->actingAs($this->user('manager'))->put(route('journal.submission.update', $sub->id), [
            'status' => 'published', 'tgl_terbit' => '2026-09-01', 'ojs_password' => '',
        ])->assertRedirect();

        $sub->refresh();
        $this->assertSame('published', $sub->status);
        $this->assertSame('2026-09-01', $sub->tgl_terbit->toDateString());
        $this->assertSame('lama', $sub->ojs_password);
    }

    /** @test */
    public function marketing_cannot_add_submission(): void
    {
        $j = $this->journal();
        $this->actingAs($this->user('marketing'))
            ->post(route('journal.submission.store', $j->id), ['status' => 'submitted'])
            ->assertForbidden();
    }
}
