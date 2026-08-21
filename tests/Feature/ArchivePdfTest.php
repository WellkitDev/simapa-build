<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\TitleProgress;
use App\Models\TitleArchive;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ArchivePdfTest extends TestCase
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

    private function book(string $archiveStatus): Title
    {
        $book = Title::create(['title' => 'Buku PDF ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $owner = $this->user('production');
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 100000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $order->details->id, 'status' => 'terbit', 'assigned_role' => 'superadmin', 'started_at' => now()]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        TitleArchive::create(['title_id' => $book->id, 'status' => $archiveStatus, 'approved_at' => now()]);
        return $book->fresh();
    }

    /** @test */
    public function pdf_streams_for_approved_title(): void
    {
        $book = $this->book('disetujui');
        $this->actingAs($this->user('manager'))->get(route('archive.pdf', $book->id))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    /** @test */
    public function pdf_forbidden_when_not_approved(): void
    {
        $book = $this->book('diajukan');
        $this->actingAs($this->user('manager'))->get(route('archive.pdf', $book->id))->assertForbidden();
    }

    /** @test */
    public function pdf_forbidden_for_marketing(): void
    {
        $book = $this->book('disetujui');
        $this->actingAs($this->user('marketing'))->get(route('archive.pdf', $book->id))->assertForbidden();
    }

    /**
     * Isi PDF diuji lewat render Blade-nya, bukan lewat binary hasil stream — assert
     * pada PDF jadi hanya bisa memeriksa content-type, dan itulah sebabnya seluruh
     * penataan ulang laporan ini nyaris tak terjaga apa pun.
     */
    private function render(Title $book): string
    {
        $book = $book->fresh()->load([
            'chapters', 'scope', 'bookIsbn', 'archive.approver', 'archive.submitter',
            'archiveArtifacts.pic', 'orderDetails.order.user', 'orderDetails.titleProgress',
        ]);
        $svc = app(\App\Services\TitleArchivalService::class);

        return view('archive.pdf', [
            'title'     => $book,
            'artifacts' => $svc->defaultArtifacts($book),
            'custom'    => $book->archiveArtifacts->where('is_custom', true)->values(),
            'isPaidOff' => $book->isPaidOff(),
            'isFinal'   => $book->manuscriptIsFinal(),
            'riwayat'   => $svc->riwayatLengkap($book),
        ])->render();
    }

    /** @test */
    public function laporan_memuat_klausul_tanggung_jawab(): void
    {
        $html = $this->render($this->book('disetujui'));

        $this->assertStringContainsString('Keterangan', $html);
        $this->assertStringContainsString('menjadi tanggung', $html);
        $this->assertStringContainsString('Penanggung Jawab naskah (admin)', $html);
        $this->assertStringContainsString('Pelaksana pembuatan naskah', $html);
    }

    /** @test */
    public function laporan_memuat_blok_mengetahui(): void
    {
        $html = $this->render($this->book('disetujui'));

        $this->assertStringContainsString('Mengetahui,', $html);
        $this->assertStringContainsString('Penanggung Jawab Naskah,', $html);
        $this->assertStringContainsString('PT AVID MEDIA INDONESIA', $html);
    }

    /**
     * Nama di klausul tanggung jawab diambil dari naskahnya, bukan diketik ulang —
     * dokumen ini tak boleh menyebut orang yang berbeda dari yang tercatat memegangnya.
     *
     * @test
     */
    public function nama_pj_dan_pelaksana_diambil_dari_naskah(): void
    {
        $book = $this->book('disetujui');
        $pj   = $this->user('admin');
        $pel  = $this->user('production');
        TitleProgress::where('order_detail_id', $book->orderDetails->first()->id)
            ->update(['pj_user_id' => $pj->id, 'pelaksana_user_id' => $pel->id]);

        $html = $this->render($book);

        $this->assertStringContainsString($pj->name, $html);
        $this->assertStringContainsString($pel->name, $html);
    }

    /** @test */
    public function laporan_memuat_seluruh_bagian_berurutan(): void
    {
        $html = $this->render($this->book('disetujui'));

        // Dicocokkan pada markup judulnya, bukan teks polos: komentar CSS di dalam
        // <style> ikut terkirim ke output, dan strpos('Keterangan') menemukannya lebih
        // dulu di kepala dokumen — urutan yang benar pun terbaca salah.
        $urutan = ['<h2>Info Judul</h2>', '<h2>Info Order</h2>', '<h2>Info Manuskrip</h2>',
                   '<h2>Penanggung Jawab &amp; Pelaksana</h2>', '<h2>Artefak Penyelesaian</h2>',
                   '<h2>Riwayat Perubahan</h2>', '<h4>Keterangan</h4>'];

        $posisi = -1;
        foreach ($urutan as $bagian) {
            $kini = strpos($html, $bagian);
            $this->assertNotFalse($kini, "bagian '{$bagian}' tidak ada di laporan");
            $this->assertGreaterThan($posisi, $kini, "bagian '{$bagian}' keluar dari urutan A-Z");
            $posisi = $kini;
        }
    }

    /** Kolom PIC sudah dicabut — tak boleh muncul lagi di laporan. */
    /** @test */
    public function laporan_tak_lagi_punya_kolom_pic(): void
    {
        $this->assertStringNotContainsString('>PIC<', $this->render($this->book('disetujui')));
    }
}
