<?php
// tests/Unit/AdminDashboardServiceTest.php

namespace Tests\Unit;

use App\Models\Announcement;
use App\Models\BookIsbn;
use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleArchive;
use App\Models\TitleDocChecklist;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdminDashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        $this->svc = new AdminDashboardService();
    }

    private function title(array $attrs = []): Title
    {
        return Title::create(array_merge([
            'title'       => 'Judul ' . uniqid(),
            'jenis'       => 'buku',
            'tipe_naskah' => 'mandiri',
            'status'      => 'disetujui',
            'slug'        => uniqid(),
        ], $attrs));
    }

    /** @test */
    public function menghitung_buku_yang_dokumennya_belum_diajukan(): void
    {
        $sudah = $this->title();
        TitleDocChecklist::create([
            'title_id' => $sudah->id, 'status' => 'diajukan', 'submitted_at' => now(),
        ]);
        $this->title();                          // buku, belum diajukan → dihitung
        $this->title(['jenis' => 'artikel']);    // artikel → tidak pernah punya checklist

        $this->assertSame(1, $this->svc->forAdmin()['doc_belum_lengkap']);
    }

    /** Judul yang naskahnya sudah final — order + progress tahap 'terbit'. */
    private function judulFinal(): Title
    {
        $title = $this->title();
        $order = Order::create([
            'code_order' => 'ORD-' . uniqid(), 'user_id' => User::factory()->create()->id,
            'status' => 'pending', 'ordered_at' => now(),
        ]);
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'title_id' => $title->id, 'type' => 'bk_mandiri',
            'title' => $title->title, 'slug' => 'od-' . uniqid(), 'chapters' => 1,
            'cost_amount' => 500000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'terbit',
            'assigned_role' => 'production', 'started_at' => now(), 'archived_at' => now(),
        ]);

        return $title->fresh();
    }

    /**
     * Ubin "Arsip Menunggu Artefak" DULU menghitung TitleArchive berstatus 'draft'.
     * Tak ada satu pun kode yang pernah membuat baris 'draft' (TitleArchivalService cuma
     * menulis diajukan/disetujui/ditolak), jadi angkanya abadi 0 di produksi — versi lama
     * test ini hijau semata karena ia membuat sendiri baris yang mustahil itu.
     *
     * Sekarang ubinnya menghitung judul final yang arsipnya belum diajukan/disetujui,
     * yakni daftar "Siap Diarsipkan" yang jadi tujuan tautannya.
     *
     * @test
     */
    public function menghitung_judul_final_yang_arsipnya_belum_diajukan(): void
    {
        $this->judulFinal();                                   // final, tanpa arsip → dihitung
        $this->title();                                        // tanpa order sama sekali → tidak
        TitleArchive::create(['title_id' => $this->judulFinal()->id, 'status' => 'diajukan']);
        TitleArchive::create(['title_id' => $this->judulFinal()->id, 'status' => 'disetujui']);
        // Ditolak harus kembali terhitung supaya bisa diperbaiki lalu diajukan ulang.
        TitleArchive::create(['title_id' => $this->judulFinal()->id, 'status' => 'ditolak']);

        $d = $this->svc->forAdmin();
        $this->assertSame(2, $d['arsip_menunggu_artefak']);
        $this->assertSame(1, $d['arsip_diajukan']);
    }

    /** @test */
    public function menghitung_submission_jurnal_aktif_dan_isbn_per_status(): void
    {
        $t = $this->title();
        $j = Journal::create(['nama' => 'Jurnal Uji']);
        JournalSubmission::create(['journal_id' => $j->id, 'title_id' => $t->id, 'status' => 'submitted']);
        JournalSubmission::create(['journal_id' => $j->id, 'title_id' => $t->id, 'status' => 'published']);
        BookIsbn::create(['title_id' => $t->id, 'status' => 'pendaftaran']);
        BookIsbn::create(['title_id' => $this->title()->id, 'status' => 'ber_isbn']);

        $d = $this->svc->forAdmin();
        $this->assertSame(1, $d['jurnal_submission_aktif']);  // submitted+loa, bukan published
        $this->assertSame(1, $d['isbn_pendaftaran']);
        $this->assertSame(1, $d['isbn_ber_isbn']);
        $this->assertSame(0, $d['isbn_cetak']);
    }

    /** @test */
    public function menghitung_pengumuman_aktif(): void
    {
        Announcement::create([
            'title' => 'A', 'body' => 'x', 'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        Announcement::create([
            'title' => 'B', 'body' => 'x', 'status' => 'draft', 'published_at' => null,
        ]);

        $this->assertSame(1, $this->svc->forAdmin()['pengumuman_aktif']);
    }

    /** @test */
    public function tidak_pernah_mengembalikan_angka_uang(): void
    {
        $keys = array_keys($this->svc->forAdmin());
        foreach (['pemasukan', 'income', 'piutang', 'laba', 'saldo', 'komisi'] as $terlarang) {
            foreach ($keys as $k) {
                $this->assertStringNotContainsString($terlarang, $k);
            }
        }
    }
}
