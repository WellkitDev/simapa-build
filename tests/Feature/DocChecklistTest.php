<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\DocRequirement;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class DocChecklistTest extends TestCase
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

    private function book(): Title
    {
        return Title::create(['title' => 'Buku Doc ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    /** @test */
    public function superadmin_crud_requirement(): void
    {
        $sa = $this->user('superadmin');
        $this->actingAs($sa)->post(route('doc-req.store'), ['category' => 'penerbit', 'label' => 'Item Baru'])->assertRedirect();
        $req = DocRequirement::where('label', 'Item Baru')->first();
        $this->assertNotNull($req);

        $this->actingAs($sa)->put(route('doc-req.update', $req->id), ['category' => 'penerbit', 'label' => 'Item Ubah'])->assertRedirect();
        $this->assertSame('Item Ubah', $req->fresh()->label);

        $this->actingAs($sa)->delete(route('doc-req.destroy', $req->id))->assertRedirect();
        $this->assertNull(DocRequirement::find($req->id));
    }

    /** @test */
    public function non_superadmin_cannot_crud_template(): void
    {
        $this->actingAs($this->user('admin'))->post(route('doc-req.store'), ['category' => 'penerbit', 'label' => 'X'])->assertRedirect()->assertSessionHas('error');
    }

    /** @test */
    public function admin_saves_marks_and_uploads(): void
    {
        // mock drive kembalikan url
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'x', 'name' => 'f', 'url' => 'http://drive/f.pdf']);
        });

        $book = $this->book();
        $rid = DocRequirement::where('category', 'penerbit')->first()->id;

        $this->actingAs($this->user('admin'))->put(route('title.doc.save', $book->id), [
            'marks' => [
                $rid => ['status' => 'ada', 'catatan' => 'ok', 'file' => \Illuminate\Http\UploadedFile::fake()->create('naskah.pdf', 20)],
            ],
        ])->assertRedirect(route('title.show', $book->id));

        $mark = \App\Models\TitleDocMark::where('title_id', $book->id)->where('doc_requirement_id', $rid)->first();
        $this->assertSame('ada', $mark->status);
        $this->assertSame('http://drive/f.pdf', $mark->file_url);
    }

    /** @test */
    public function admin_submits_checklist(): void
    {
        $book = $this->book();
        $this->actingAs($this->user('admin'))->post(route('title.doc.submit', $book->id))
            ->assertRedirect(route('title.show', $book->id));
        $cl = \App\Models\TitleDocChecklist::where('title_id', $book->id)->first();
        $this->assertSame('diajukan', $cl->status);
    }

    /** @test */
    public function manager_and_marketing_cannot_mark(): void
    {
        $book = $this->book();
        foreach (['manager', 'marketing'] as $role) {
            $this->actingAs($this->user($role))->put(route('title.doc.save', $book->id), ['marks' => []])
                ->assertRedirect()->assertSessionHas('error');
        }
    }

    /** @test */
    public function card_renders_grouped_items_with_progress(): void
    {
        $book = $this->book();
        $this->actingAs($this->user('admin'))->get(route('title.show', $book->id))
            ->assertOk()
            ->assertSee('Cek Kelengkapan Data')
            ->assertSee('Dokumen Penerbit (ISBN)')
            ->assertSee('Dokumen HKI (Hak Cipta)')
            ->assertSee('Surat Pernyataan Keaslian Karya');
    }
}
