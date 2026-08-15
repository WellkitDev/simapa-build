<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tombol Ajukan di halaman detail judul.
 *
 * Halaman detail dulu memakai isEditable() sebagai penjaga — dan isEditable() sengaja
 * memuat 'disetujui' supaya salah ketik masih bisa diperbaiki. Akibatnya judul yang
 * sudah disetujui tetap menampilkan tombol Ajukan yang bisa diklik, lalu
 * TitleService::submit() diam-diam tidak melakukan apa pun tapi tetap menjawab
 * "Judul diajukan.". Tombolnya kini dinonaktifkan, bukan disembunyikan.
 */
class TitleSubmitButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('admin');
        return $u;
    }

    private function title(string $status, string $jenis = 'artikel'): Title
    {
        return Title::create([
            'title' => 'Judul Uji ' . $status, 'code' => 'UJI' . random_int(100, 999),
            'jenis' => $jenis, 'tipe_naskah' => 'mandiri', 'status' => $status,
            'asal' => 'distribusi', 'created_by' => $this->admin()->id,
        ]);
    }

    /** @test */
    public function judul_draft_masih_bisa_diajukan(): void
    {
        $title = $this->title('draft');

        $html = $this->actingAs($this->admin())->get(route('title.show', $title->id))
            ->assertOk()->getContent();

        $this->assertStringContainsString(route('title.submit', $title->id), $html,
            'Judul draf tanpa order harus tetap punya form Ajukan yang aktif.');
        $this->assertNull($title->submitBlockReason());
    }

    /** @test */
    public function judul_ditolak_masih_bisa_diajukan_ulang(): void
    {
        $title = $this->title('ditolak');

        $this->assertNull($title->submitBlockReason());
        $this->actingAs($this->admin())->get(route('title.show', $title->id))
            ->assertOk()->assertSee(route('title.submit', $title->id), false);
    }

    /** @test */
    public function judul_menunggu_tombol_ajukan_nonaktif(): void
    {
        $title = $this->title('menunggu');

        $this->assertSame('Sudah diajukan, menunggu persetujuan', $title->submitBlockReason());

        $html = $this->actingAs($this->admin())->get(route('title.show', $title->id))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(route('title.submit', $title->id), $html,
            'Judul yang sudah diajukan tidak boleh punya form Ajukan yang bisa dikirim.');
        $this->assertStringContainsString('Sudah diajukan, menunggu persetujuan', $html,
            'Alasan penguncian harus terbaca user, bukan tombol mati tanpa keterangan.');
    }

    /** @test */
    public function judul_disetujui_tombol_ajukan_nonaktif_bukan_hilang(): void
    {
        $title = $this->title('disetujui');

        $this->assertSame('Sudah disetujui', $title->submitBlockReason());

        $html = $this->actingAs($this->admin())->get(route('title.show', $title->id))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(route('title.submit', $title->id), $html);
        // Tombolnya tetap terlihat (nonaktif), bukan hilang tanpa penjelasan.
        $this->assertStringContainsString('<button type="button" class="btn btn-sm btn-info" disabled>Ajukan</button>', $html);
    }

    /** @test */
    public function judul_draft_yang_sudah_dipakai_order_tidak_bisa_diajukan(): void
    {
        $title = $this->title('draft', 'buku');
        $order = Order::factory()->create();

        OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri',
            'title' => $title->title, 'slug' => 'judul-uji-order-' . $order->id,
            'title_id' => $title->id,
            'naskah_type' => 'mandiri', 'publication_type' => 'regular', 'cost_amount' => 1000000,
        ]);

        $this->assertSame('Sudah dipakai 1 order', $title->fresh()->submitBlockReason());

        $html = $this->actingAs($this->admin())->get(route('title.show', $title->id))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(route('title.submit', $title->id), $html);
    }

    /**
     * Penjaga tampilan saja tidak cukup: pengguna yang mengirim POST langsung tetap
     * tidak boleh mengubah status judul yang sudah disetujui.
     *
     * @test
     */
    public function post_langsung_tidak_mengubah_judul_yang_sudah_disetujui(): void
    {
        $title = $this->title('disetujui');

        $this->actingAs($this->admin())->post(route('title.submit', $title->id))->assertRedirect();

        $this->assertSame('disetujui', $title->fresh()->status);
    }
}
