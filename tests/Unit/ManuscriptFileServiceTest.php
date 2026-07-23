<?php

namespace Tests\Unit;

use App\Models\Title;
use App\Models\TitleChapter;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\ManuscriptFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class ManuscriptFileServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ManuscriptFileService
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('uploadFile')->andReturn(['id' => 'drv1', 'name' => 'n', 'url' => 'http://drive/n.pdf']);
        return new ManuscriptFileService($drive);
    }

    private function book(): Title
    {
        return Title::create(['title' => 'Buku Naskah ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    /** @test */
    public function upload_creates_version_1_then_increments(): void
    {
        $title = $this->book();
        $actor = User::factory()->create();
        $svc = $this->service();

        $f1 = $svc->upload($title, null, 'final', UploadedFile::fake()->create('a.pdf', 5), $actor);
        $f2 = $svc->upload($title, null, 'final', UploadedFile::fake()->create('b.pdf', 5), $actor);

        $this->assertSame(1, $f1->version);
        $this->assertSame(2, $f2->version);
        $this->assertSame('http://drive/n.pdf', $f2->drive_url);
        $this->assertSame($actor->id, $f2->uploaded_by);
    }

    /** @test */
    public function versions_are_isolated_per_slot_and_chapter(): void
    {
        $title = $this->book();
        $chapter = TitleChapter::create(['title_id' => $title->id, 'judul' => 'Bab 1', 'urutan' => 1]);
        $actor = User::factory()->create();
        $svc = $this->service();

        $svc->upload($title, null, 'masuk', UploadedFile::fake()->create('m.pdf', 5), $actor);      // title/masuk v1
        $svc->upload($title, null, 'final', UploadedFile::fake()->create('f.pdf', 5), $actor);      // title/final v1
        $chFile = $svc->upload($title, $chapter, 'final', UploadedFile::fake()->create('c.pdf', 5), $actor); // chapter/final v1

        $this->assertSame(1, $chFile->version);
        $this->assertSame(1, $svc->latest($title, null, 'masuk')->version);
        $this->assertNull($svc->latest($title, $chapter->id, 'masuk'));
    }

    /** @test */
    public function invalid_slot_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->upload($this->book(), null, 'draft', UploadedFile::fake()->create('x.pdf', 5), User::factory()->create());
    }
}
