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

    /** Berkas naskah final level judul — sumber item kelengkapan yang otomatis. */
    private function naskahFinal(Title $book, int $version = 1): \App\Models\ManuscriptFile
    {
        return \App\Models\ManuscriptFile::create([
            'title_id' => $book->id, 'title_chapter_id' => null, 'slot' => 'final',
            'version' => $version, 'original_name' => 'buku-final.pdf',
            'drive_url' => 'http://drive/final.pdf', 'created_at' => now(),
        ]);
    }

    /** @test */
    public function progress_counts_ada_over_active_total_per_category(): void
    {
        $book = $this->book();
        // Item pertama penerbit kini otomatis (Naskah Lengkap ← slot Naskah Final),
        // jadi yang bisa ditandai manual mulai dari item kedua.
        $penerbit = DocRequirement::where('category', 'penerbit')->whereNull('auto_source')
            ->orderBy('position')->get();
        TitleDocMark::create(['title_id' => $book->id, 'doc_requirement_id' => $penerbit[0]->id, 'status' => 'ada']);
        TitleDocMark::create(['title_id' => $book->id, 'doc_requirement_id' => $penerbit[1]->id, 'status' => 'ada']);

        $prog = $this->service()->progress($book, 'penerbit');
        $this->assertSame(5, $prog['total']); // 5 item penerbit ter-seed
        $this->assertSame(2, $prog['done']);
    }

    /**
     * "Naskah Lengkap (Final Draft)" adalah berkas yang SAMA dengan slot Naskah Final di
     * Pelacakan Naskah. Diminta dua kali, keduanya bisa berbeda isi dan tak ada cara tahu
     * mana yang benar — jadi item ini dipenuhi dari sumbernya, bukan diunggah ulang.
     *
     * @test
     */
    public function item_naskah_lengkap_tercentang_saat_slot_naskah_final_terisi(): void
    {
        $book = $this->book();
        $req  = DocRequirement::where('auto_source', 'naskah_final')->firstOrFail();

        // Belum ada berkas final → belum terpenuhi, walau tak ada TitleDocMark sama sekali.
        $this->assertNull($this->service()->autoFile($book, $req));
        $this->assertSame(0, $this->service()->progress($book, 'penerbit')['done']);

        $this->naskahFinal($book);

        $this->assertNotNull($this->service()->autoFile($book, $req));
        $this->assertSame(1, $this->service()->progress($book, 'penerbit')['done']);
    }

    /** @test */
    public function item_otomatis_mengambil_versi_terbaru_naskah_final(): void
    {
        $book = $this->book();
        $req  = DocRequirement::where('auto_source', 'naskah_final')->firstOrFail();

        $this->naskahFinal($book, 1);
        $baru = $this->naskahFinal($book, 2);

        $this->assertSame($baru->id, $this->service()->autoFile($book, $req)->id);
    }

    /** @test */
    public function file_bab_tidak_dianggap_naskah_final_buku(): void
    {
        $book = $this->book();
        $req  = DocRequirement::where('auto_source', 'naskah_final')->firstOrFail();
        $bab  = $book->chapters()->create(['judul' => 'Bab 1', 'urutan' => 1]);

        \App\Models\ManuscriptFile::create([
            'title_id' => $book->id, 'title_chapter_id' => $bab->id, 'slot' => 'final',
            'version' => 1, 'original_name' => 'bab1.pdf', 'drive_url' => 'http://drive/bab1.pdf',
            'created_at' => now(),
        ]);

        $this->assertNull($this->service()->autoFile($book, $req),
            'Berkas final milik BAB bukan naskah final level buku.');
    }

    /** @test */
    public function isian_manual_tidak_bisa_menimpa_status_item_otomatis(): void
    {
        $book  = $this->book();
        $req   = DocRequirement::where('auto_source', 'naskah_final')->firstOrFail();
        $actor = User::factory()->create();

        $this->service()->saveMarks($book, [
            ['requirement_id' => $req->id, 'status' => 'ada', 'catatan' => 'akal-akalan', 'file' => null],
        ], $actor);

        $this->assertNull(
            TitleDocMark::where('title_id', $book->id)->where('doc_requirement_id', $req->id)->first(),
            'Item otomatis tidak boleh punya penanda manual — sumber kebenarannya berkas naskah.'
        );
        $this->assertSame(0, $this->service()->progress($book, 'penerbit')['done']);
    }

    /** @test */
    public function save_marks_upserts_status_and_note(): void
    {
        $book = $this->book();
        // Item manual — yang ber-auto_source sengaja tak menerima isian dari layar ini.
        $rid = DocRequirement::where('category', 'penerbit')->whereNull('auto_source')->first()->id;
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
