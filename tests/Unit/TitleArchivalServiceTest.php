<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\TitleProgress;
use App\Models\TitleArchiveArtifact;
use App\Services\TitleArchivalService;
use App\Services\GoogleDriveService;
use App\Services\Notifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Mockery;

class TitleArchivalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manager', 'superadmin'] as $r) { Role::create(['name' => $r, 'guard_name' => 'web']); }
    }

    private function service(): TitleArchivalService
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('uploadFile')->andReturn(['id' => 'x', 'name' => 'f', 'url' => 'http://drive/f.pdf']);
        return new TitleArchivalService($drive);
    }

    private function eligibleBook(): Title
    {
        $book = Title::create(['title' => 'Buku ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $owner = User::factory()->create();
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 100000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $order->details->id, 'status' => 'terbit', 'assigned_role' => 'superadmin', 'started_at' => now()]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        return $book->fresh();
    }

    /** @test */
    public function save_artifacts_upserts_fixed_custom_and_uploads(): void
    {
        $book = $this->eligibleBook();
        $actor = User::factory()->create();
        // `pic_user_id` sengaja tetap DIKIRIM di sini meski formulirnya tak lagi
        // memilikinya: kiriman lama (tab yang belum di-refresh, permintaan yang
        // diulang) tak boleh diam-diam menghidupkan kembali kolom yang sudah pensiun.
        $this->service()->saveArtifacts($book, [
            'isbn'         => ['value' => '978-1', 'pic_user_id' => $actor->id, 'note' => 'ok'],
            'barcode_file' => ['file' => UploadedFile::fake()->create('bc.pdf', 5), 'pic_user_id' => $actor->id],
        ], [
            ['label' => 'Sertifikat', 'type' => 'link', 'value' => 'http://x', 'pic_user_id' => $actor->id],
        ], $actor);

        $isbn = TitleArchiveArtifact::where('title_id', $book->id)->where('key', 'isbn')->first();
        $this->assertSame('978-1', $isbn->value);
        $this->assertSame('ok', $isbn->note, 'catatan per artefak tetap disimpan');
        $this->assertNull(
            $isbn->pic_user_id,
            'PIC tak lagi ditulis — penanggung jawab dibaca dari naskahnya lewat riwayatLengkap()'
        );
        $barcode = TitleArchiveArtifact::where('title_id', $book->id)->where('key', 'barcode_file')->first();
        $this->assertSame('http://drive/f.pdf', $barcode->value);
        $this->assertSame(1, TitleArchiveArtifact::where('title_id', $book->id)->where('is_custom', true)->count());
    }

    /** @test */
    public function submit_requires_eligibility(): void
    {
        $notEligible = Title::create(['title' => 'X', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->expectException(ValidationException::class);
        $this->service()->submit($notEligible, User::factory()->create());
    }

    /** @test */
    public function submit_ok_and_approve_reject(): void
    {
        $this->mock(Notifier::class, fn ($m) => $m->shouldReceive('titleArchiveSubmitted'));

        $book = $this->eligibleBook();
        $actor = User::factory()->create();
        $archive = $this->service()->submit($book, $actor);
        $this->assertSame('diajukan', $archive->status);
        $this->assertSame($actor->id, $archive->submitted_by);

        $sa = User::factory()->create();
        $this->service()->approve($book, $sa, 'lengkap');
        $this->assertSame('disetujui', $book->archive()->first()->status);
        $this->assertSame('lengkap', $book->archive()->first()->approval_note);

        $this->service()->reject($book, $sa, 'kurang');
        $this->assertSame('ditolak', $book->archive()->first()->status);
    }
}
