<?php
// tests/Feature/GroupKeyIkutJudulTest.php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderContact;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `group_key` adalah satu-satunya hal yang menyatukan beberapa order menjadi SATU kartu
 * di papan Pelacakan. Kalau ia tak ikut berpindah saat judul order diganti, satu buku
 * pecah jadi dua kartu — dan itulah yang terlihat pengguna sebagai "judul yang sama
 * muncul dua kali, satu mandiri dan lima dibuatkan".
 *
 * `OrderDetail::booted()` menjaganya lewat hook `saving`. Hook itu HANYA berbunyi kalau
 * penyimpanannya lewat model. `$order->details()->update([...])` adalah update
 * query-builder: ia menulis langsung ke basis data dan melewati seluruh event Eloquent
 * tanpa satu pun peringatan. Kedua controller order dulu memakai bentuk itu.
 *
 * Ditemukan di data produksi 2026-08-23: satu order_detail ber-`title_id` 95 masih
 * memegang `group_key` 'title:66'.
 */
class GroupKeyIkutJudulTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    /** Order + detail + kontak yang siap disunting lewat layar. */
    private function order(string $kode, string $tipe, string $judul): Order
    {
        $mkt   = $this->user('marketing');
        $order = Order::create([
            'code_order' => $kode, 'user_id' => $mkt->id,
            'status' => 'pending', 'ordered_at' => '2026-08-01',
        ]);

        $title = Title::create([
            'title'       => $judul,
            'jenis'       => str_starts_with($tipe, 'bk_') ? 'buku' : 'artikel',
            'tipe_naskah' => 'mandiri',
            'status'      => 'disetujui',
        ]);

        OrderDetail::create([
            'order_id' => $order->id, 'type' => $tipe, 'title' => $judul,
            'title_id' => $title->id, 'slug' => 'j-' . $order->id,
            'indexation' => 'sinta 3', 'naskah_type' => 'mandiri',
            'publication_type' => 'regular', 'cost_amount' => 100,
        ]);
        OrderContact::create(['order_id' => $order->id, 'cp_phone' => '08', 'cp_email' => 'e@example.com']);

        return $order->fresh();
    }

    /** @test */
    public function judul_buku_diganti_lewat_layar_membawa_group_key_ikut_pindah(): void
    {
        $order = $this->order('ORD-202608-0101', 'bk_mandiri', 'Judul Buku Lama');
        $lama  = $order->details->title_id;

        $this->actingAs($this->user('superadmin'))
            ->put(route('order.book.update', $order->id), [
                'type' => 'bk_mandiri', 'title_id' => 'Judul Buku Baru', 'scope_id' => '',
                'chapters' => 3, 'naskah_type' => 'dibuatkan', 'publication_type' => 'regular',
                'issued_at' => '2026-08-02', 'cost_amount' => 200,
                'contact_phone' => '09', 'contact_email' => 'e2@example.com',
                'authors' => [['name' => 'B', 'email' => 'b@example.com', 'position' => 1]],
            ])->assertRedirect();

        $detail = $order->details()->first();

        $this->assertNotSame($lama, $detail->title_id, 'Judulnya memang harus berpindah.');
        $this->assertSame(
            'title:' . $detail->title_id,
            $detail->group_key,
            'group_key tertinggal di judul lama — papan akan memecah satu buku jadi dua kartu.'
        );
    }

    /** @test */
    public function judul_artikel_diganti_lewat_layar_membawa_group_key_ikut_pindah(): void
    {
        $order = $this->order('ORD-202608-0102', 'at_mandiri', 'Judul Artikel Lama');
        $lama  = $order->details->title_id;

        $this->actingAs($this->user('superadmin'))
            ->put(route('order.journal.update', $order->code_order), [
                'type' => 'at_kolab', 'title_id' => 'Judul Artikel Baru', 'scope_id' => '',
                'indexation' => 'sinta 2', 'naskah_type' => 'mandiri', 'publication_type' => 'fastrack',
                'issued_at' => '2026-08-02', 'cost_amount' => 200,
                'contact_phone' => '09', 'contact_email' => 'e2@example.com',
                'authors' => [['name' => 'B', 'email' => 'b@example.com', 'position' => 1]],
            ])->assertRedirect();

        $detail = $order->details()->first();

        $this->assertNotSame($lama, $detail->title_id, 'Judulnya memang harus berpindah.');
        $this->assertSame(
            'title:' . $detail->title_id,
            $detail->group_key,
            'group_key tertinggal di judul lama — papan akan memecah satu artikel jadi dua kartu.'
        );
    }

    /**
     * Bentuk yang sebenarnya terjadi di produksi, dan gejala yang dilihat pengguna.
     *
     * Order kedua DIPINDAHKAN ke judul yang sudah dipakai order pertama. Sesudahnya
     * kedua detail menunjuk `title_id` yang sama — tapi yang dipindah masih memegang
     * `group_key` judul lamanya, jadi papan memecah satu buku jadi dua kartu.
     *
     * @test
     */
    public function order_yang_dipindah_ke_judul_lain_tidak_memecah_kartu(): void
    {
        $pertama = $this->order('ORD-202608-0103', 'bk_kolab', 'Buku Kolaborasi');
        $kedua   = $this->order('ORD-202608-0104', 'bk_kolab', 'Buku Yang Salah Ketik');

        $tujuan = $pertama->details->title_id;

        // Order kedua disunting supaya menaut judul milik order pertama.
        $this->actingAs($this->user('superadmin'))
            ->put(route('order.book.update', $kedua->id), [
                'type' => 'bk_kolab', 'title_id' => 'Buku Kolaborasi', 'scope_id' => '',
                'chapters' => 2, 'naskah_type' => 'dibuatkan', 'publication_type' => 'regular',
                'issued_at' => '2026-08-02', 'cost_amount' => 200,
                'contact_phone' => '09', 'contact_email' => 'e2@example.com',
                'authors' => [['name' => 'B', 'email' => 'b@example.com', 'position' => 1]],
            ])->assertRedirect();

        $this->assertSame($tujuan, $kedua->details()->first()->title_id,
            'Prasyarat tes: kedua order harus berakhir di judul yang sama.');

        $pecah = OrderDetail::whereNull('deleted_at')
            ->selectRaw('title_id, COUNT(DISTINCT group_key) AS g')
            ->groupBy('title_id')
            ->havingRaw('g > 1')
            ->get();

        $this->assertCount(0, $pecah,
            'Ada title_id dengan lebih dari satu group_key — papan menampilkannya sebagai kartu ganda.');
    }
}
