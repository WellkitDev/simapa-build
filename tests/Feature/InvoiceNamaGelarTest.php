<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Support\InvoicePdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Nama, gelar, judul, dan afiliasi harus tampil PERSIS seperti yang diketik.
 *
 * Invoice PDF membungkus teks-teks itu dengan Str::title(), yang menjalankan
 * ucwords(strtolower(...)). ucwords hanya mengenali SPASI sebagai pemisah kata,
 * jadi apa pun yang mengandung titik atau huruf besar berurutan jadi rusak:
 *
 *   Dr. Rinovian Rais.,M.M.,M.Pd  ->  Dr. Rinovian Rais.,M.m.,M.pd
 *   Prof. Dr. H. Ahmad, S.H.      ->  Prof. Dr. H. Ahmad, S.h.
 *   Manajemen Berbasis AI         ->  Manajemen Berbasis Ai
 *   UIN Syarif Hidayatullah       ->  Uin Syarif Hidayatullah
 *
 * Gelar akademik yang salah tulis di dokumen tagihan bukan kesalahan kosmetik —
 * ia dikirim ke klien atas nama perusahaan.
 */
class InvoiceNamaGelarTest extends TestCase
{
    use RefreshDatabase;

    private const NAMA       = 'Dr. Rinovian Rais.,M.M.,M.Pd';
    private const AFILIASI   = 'UIN Syarif Hidayatullah';
    private const JUDUL      = 'Manajemen Berbasis AI: Memimpin Tim';

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function invoiceDenganAuthor(): Invoice
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');

        $order  = Order::create([
            'code_order' => 'ORD-GELAR-1', 'user_id' => $u->id,
            'status' => 'pending', 'ordered_at' => today()->toDateString(),
        ]);
        $detail = OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => self::JUDUL,
            'slug' => 'judul-gelar-' . $order->id, 'naskah_type' => 'mandiri',
            'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);
        // authors adalah belongsToMany lewat pivot tb_author_orders; mengisi
        // order_detail_id di baris Author tak menyambungkan apa pun.
        $author = Author::create(['name' => self::NAMA, 'affiliation' => self::AFILIASI]);
        $detail->authors()->attach($author->id, ['position' => 1]);
        $payment = Payment::create([
            'order_id' => $order->id, 'amount' => 500000,
            'payment_type' => 'dp', 'status' => 'paid', 'paid_at' => now(),
        ]);

        return Invoice::create([
            'order_id' => $order->id, 'payment_id' => $payment->id, 'invoice_no' => 'INV-GELAR-1',
            'status' => 'diterbitkan', 'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(7)->toDateString(),
        ]);
    }

    /** Merender blade PDF-nya, bukan PDF-nya sendiri: teks di dalam PDF terkompresi. */
    private function html(): string
    {
        return view('payments.invoices.book_invoice_pdf', InvoicePdfData::for($this->invoiceDenganAuthor()))->render();
    }

    /** @test */
    public function gelar_akademik_tidak_diubah_huruf_besar_kecilnya(): void
    {
        $html = $this->html();

        $this->assertStringContainsString(self::NAMA, $html);
        $this->assertStringNotContainsString('M.m.', $html, 'Gelar M.M. tak boleh jadi M.m.');
        $this->assertStringNotContainsString('M.pd', $html, 'Gelar M.Pd tak boleh jadi M.pd.');
    }

    /** @test */
    public function akronim_pada_judul_dan_afiliasi_tetap_utuh(): void
    {
        $html = $this->html();

        $this->assertStringContainsString(self::JUDUL, $html, 'Akronim AI tak boleh jadi Ai.');
        $this->assertStringContainsString(self::AFILIASI, $html, 'UIN tak boleh jadi Uin.');
    }
}
