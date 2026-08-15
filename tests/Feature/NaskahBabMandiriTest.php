<?php
// tests/Feature/NaskahBabMandiriTest.php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\ChapterProgress;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\ChapterAuthorService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bab bernaskah mandiri: naskahnya dikirim authornya sendiri, jadi tak pernah punya
 * pelaksana dan tahap Pembuatan tak punya makna untuknya. Sebelum perbaikan ini bab
 * seperti itu terkunci selamanya di status awalnya, dan satu bab macet menahan
 * seluruh buku di gerbang Layout.
 */
class NaskahBabMandiriTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'drive-1', 'url' => 'https://drive/1']);
        });
    }

    private function user(string $role, ?string $bidang = null): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        if ($bidang !== null) {
            $u->profile()->create(['bidang' => $bidang]);
        }

        return $u->fresh();
    }

    /**
     * Buku kolaborasi dengan SATU order per bab — bentuk data yang sebenarnya:
     * `order_details.chapters` menyimpan NOMOR bab, dan `naskah_type` order itulah
     * yang menentukan asal naskah bab tersebut.
     *
     * @param array<int,array{0:string,1:string}> $babs nomor bab => [naskah_type, status bab]
     */
    private function buku(array $babs, string $bukuStatus = 'pembuatan'): Title
    {
        $book = Title::create([
            'title'       => 'Kolab ' . fake()->unique()->word(),
            'jenis'       => 'buku',
            'tipe_naskah' => 'kolaborasi',
            'status'      => 'disetujui',
        ]);

        foreach ($babs as $nomor => [$jenisNaskah, $statusBab]) {
            $order  = Order::factory()->create(['user_id' => $this->user('marketing')->id]);
            $detail = OrderDetail::factory()->create([
                'order_id'    => $order->id,
                'type'        => 'bk_kolab',
                'title'       => $book->title,
                'title_id'    => $book->id,
                'chapters'    => $nomor,
                'naskah_type' => $jenisNaskah,
            ]);
            $detail->authors()->attach(
                Author::create(['name' => 'Penulis ' . $nomor])->id,
                ['position' => 1]
            );
            TitleProgress::create([
                'order_detail_id' => $detail->id,
                'status'          => $bukuStatus,
                'assigned_role'   => TitleProgress::getHandlerForStatus($bukuStatus),
                'bidang'          => 'buku',
                'started_at'      => now(),
            ]);

            $bab = $book->chapters()->create(['judul' => 'Bab ' . $nomor, 'urutan' => $nomor]);
            $bab->progress()->create(['status' => $statusBab, 'started_at' => now()]);
        }

        app(ChapterAuthorService::class)->seedFromOrders($book);

        return $book->fresh();
    }

    private function bab(Title $book, int $urutan): ChapterProgress
    {
        return $book->chapters()->where('urutan', $urutan)->first()->progress;
    }

    private function detailBab(Title $book, int $urutan): OrderDetail
    {
        return $book->orderDetails()->where('chapters', $urutan)->first();
    }

    /** @test */
    public function bab_mandiri_melompati_tahap_pembuatan(): void
    {
        $book = $this->buku([1 => ['mandiri', 'menunggu'], 2 => ['dibuatkan', 'menunggu']]);

        $this->assertSame('editing', $this->bab($book, 1)->nextStage());
        $this->assertSame('pembuatan', $this->bab($book, 2)->nextStage(), 'Bab dibuatkan tidak boleh ikut melompat.');
    }

    /** @test */
    public function unggah_naskah_author_memajukan_bab_mandiri_ke_editing(): void
    {
        $book = $this->buku([1 => ['mandiri', 'menunggu'], 2 => ['dibuatkan', 'menunggu']]);
        $cp   = $this->bab($book, 1);

        // Admin bukan pelaksana bab ini — dan memang tak akan pernah ada pelaksananya.
        $this->actingAs($this->user('admin', 'buku'))
            ->post(route('naskah.bab.file', $cp->id), [
                'slot' => 'masuk',
                'file' => UploadedFile::fake()->create('bab1.pdf', 100, 'application/pdf'),
            ])->assertRedirect();

        $this->assertSame('editing', $cp->fresh()->status);
        $this->assertSame('menunggu', $this->bab($book, 2)->fresh()->status,
            'Bab dibuatkan tetap butuh pelaksana; tidak boleh ikut maju.');
    }

    /** @test */
    public function baris_bab_mandiri_menawarkan_unggahan_dan_tombol_maju(): void
    {
        $book = $this->buku([1 => ['mandiri', 'editing'], 2 => ['dibuatkan', 'menunggu']], bukuStatus: 'editing');

        $isi = $this->actingAs($this->user('admin', 'buku'))
            ->get(route('naskah.show', $this->detailBab($book, 1)->id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Naskah dari Author', $isi);
        $this->assertStringContainsString('Selesaikan Bab', $isi);
    }

    /** @test */
    public function bab_mandiri_selesai_membuka_gerbang_layout(): void
    {
        $book   = $this->buku([1 => ['mandiri', 'editing'], 2 => ['dibuatkan', 'selesai']], bukuStatus: 'editing');
        $admin  = $this->user('admin', 'buku');
        $cp     = $this->bab($book, 1);
        $detail = $this->detailBab($book, 1);

        $this->actingAs($admin)->post(route('naskah.bab.selesaikan', $cp->id))->assertRedirect();
        $this->assertSame('selesai', $cp->fresh()->status);

        // Semua bab selesai → gerbang assertLayoutUnlocked() terbuka.
        $this->actingAs($admin)->post(route('naskah.selesaikan', $detail->id))->assertRedirect();
        $this->assertSame('layout', $detail->titleProgress->fresh()->status);
    }
}
