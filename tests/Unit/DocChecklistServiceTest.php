<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\DocRequirement;
use App\Models\TitleDocMark;
use App\Models\TitleDocChecklist;
use App\Services\DocChecklistService;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class DocChecklistServiceTest extends TestCase
{
    use RefreshDatabase;

    private function book(): Title
    {
        return Title::create(['title' => 'Buku Doc ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    private function service(): DocChecklistService
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('uploadFile')->andReturn(['id' => 'x', 'name' => 'f', 'url' => 'http://drive/f.pdf']);
        return new DocChecklistService($drive);
    }

    /** @test */
    public function progress_counts_ada_over_active_total_per_category(): void
    {
        $book = $this->book();
        $penerbit = DocRequirement::where('category', 'penerbit')->orderBy('position')->get();
        // tandai 2 item penerbit 'ada'
        TitleDocMark::create(['title_id' => $book->id, 'doc_requirement_id' => $penerbit[0]->id, 'status' => 'ada']);
        TitleDocMark::create(['title_id' => $book->id, 'doc_requirement_id' => $penerbit[1]->id, 'status' => 'ada']);

        $prog = $this->service()->progress($book, 'penerbit');
        $this->assertSame(5, $prog['total']); // 5 item penerbit ter-seed
        $this->assertSame(2, $prog['done']);
    }

    /** @test */
    public function save_marks_upserts_status_and_note(): void
    {
        $book = $this->book();
        $rid = DocRequirement::where('category', 'penerbit')->first()->id;
        $actor = User::factory()->create();

        $this->service()->saveMarks($book, [
            ['requirement_id' => $rid, 'status' => 'ada', 'catatan' => 'lengkap', 'file' => null],
        ], $actor);

        $mark = TitleDocMark::where('title_id', $book->id)->where('doc_requirement_id', $rid)->first();
        $this->assertSame('ada', $mark->status);
        $this->assertSame('lengkap', $mark->catatan);
        $this->assertSame($actor->id, $mark->updated_by);
    }

    /** @test */
    public function save_marks_uploads_file_then_preserves_on_next_save_without_file(): void
    {
        $book = $this->book();
        $rid = DocRequirement::where('category', 'hki')->first()->id;
        $actor = User::factory()->create();

        $this->service()->saveMarks($book, [
            ['requirement_id' => $rid, 'status' => 'ada', 'catatan' => null, 'file' => UploadedFile::fake()->create('ktp.pdf', 10)],
        ], $actor);
        $mark = TitleDocMark::where('title_id', $book->id)->where('doc_requirement_id', $rid)->first();
        $this->assertSame('http://drive/f.pdf', $mark->file_url);
        $this->assertSame('ktp.pdf', $mark->file_name);

        // simpan lagi tanpa file → file_url dipertahankan
        $this->service()->saveMarks($book, [
            ['requirement_id' => $rid, 'status' => 'belum', 'catatan' => null, 'file' => null],
        ], $actor);
        $this->assertSame('http://drive/f.pdf', $mark->fresh()->file_url);
    }

    /** @test */
    public function submit_sets_status_diajukan(): void
    {
        $book = $this->book();
        $actor = User::factory()->create();
        $this->service()->submit($book, $actor);
        $cl = TitleDocChecklist::where('title_id', $book->id)->first();
        $this->assertSame('diajukan', $cl->status);
        $this->assertSame($actor->id, $cl->submitted_by);
        $this->assertNotNull($cl->submitted_at);
    }
}
