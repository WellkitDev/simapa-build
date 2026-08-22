<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\User;
use App\Services\ChapterAuthorService;
use App\Services\GoogleDriveService;
use App\Services\TitleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Penomoran bab dan keutuhan datanya saat judul disimpan.
 *
 * Dua bug yang ternyata satu: `TitleService::syncChapters()` memakai `urutan` 0-based
 * sementara `order_details.chapters` 1-based, sehingga seluruh peta author bergeser satu
 * langkah dan bab pertama ditandai "belum dipesan". Dan ia menghapus semua bab tiap
 * simpan, yang lewat cascade memusnahkan kemajuan bab beserta authornya.
 */
class BabUrutanDanAuthorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function aktor(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    private function buku(int $bab = 5): Title
    {
        $t = Title::create([
            'title' => 'Buku Bab ' . fake()->unique()->words(2, true),
            'jenis' => 'buku', 'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui',
        ]);

        for ($i = 1; $i <= $bab; $i++) {
            $t->chapters()->create(['judul' => "Bab {$i}", 'urutan' => $i]);
        }

        return $t->fresh();
    }

    /** Satu order per bab, masing-masing dengan authornya — pola buku kolaborasi. */
    private function pesanBab(Title $t, int $nomorBab, string $namaAuthor): OrderDetail
    {
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'bk_kolab',
            'title' => $t->title, 'title_id' => $t->id, 'chapters' => $nomorBab,
        ]);
        $author = Author::firstOrCreate(['email' => str()->slug($namaAuthor) . '@uji.test'],
            ['name' => $namaAuthor]);
        $detail->authors()->attach($author->id, ['position' => 1]);

        return $detail;
    }

    /** Data judul yang lengkap — update() menuntut jenis & tipe_naskah, bukan judul saja. */
    private function data(Title $t, array $ubah = []): array
    {
        return array_merge([
            'title'       => $t->title,
            'jenis'       => $t->jenis,
            'tipe_naskah' => $t->tipe_naskah,
        ], $ubah);
    }

    private function daftarBab(Title $t): array
    {
        return $t->fresh()->chapters()->get()
            ->map(fn ($c) => $c->judul . ' — ' . ($c->authors()->first()->name ?? ''))
            ->all();
    }

    // ─── penomoran ───

    /** @test */
    public function menyimpan_judul_menomori_bab_mulai_dari_satu(): void
    {
        $t = Title::create([
            'title' => 'Buku Baru', 'jenis' => 'buku',
            'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui',
        ]);

        app(TitleService::class)->update($t, $this->data($t), [
            ['judul' => 'Bab 1'], ['judul' => 'Bab 2'], ['judul' => 'Bab 3'],
        ], $this->aktor());

        $this->assertSame([1, 2, 3], $t->fresh()->chapters()->pluck('urutan')->all(),
            'Nomor bab mulai dari 1 — `order_details.chapters` juga 1-based.');
    }

    /**
     * Gejala yang dilaporkan: menambah bab pada judul yang sudah punya author membuat
     * seluruh author bergeser satu, dan bab pertama jadi kosong.
     *
     * @test
     */
    public function menambah_bab_tidak_menggeser_author_yang_sudah_ada(): void
    {
        $t = $this->buku(5);
        foreach (['Hizkia', 'Arif', 'Rinovian', 'Laila', 'Sayed'] as $i => $nama) {
            $this->pesanBab($t, $i + 1, $nama);
        }
        app(ChapterAuthorService::class)->seedFromOrders($t->fresh());

        $sebelum = $this->daftarBab($t);
        $this->assertSame('Bab 1 — Hizkia', $sebelum[0]);
        $this->assertSame('Bab 5 — Sayed', $sebelum[4]);

        // Tambah Bab 6 lewat jalur yang sama dengan formulir judul.
        $kirim = $t->fresh()->chapters()->get()
            ->map(fn ($c) => ['id' => $c->id, 'judul' => $c->judul])->all();
        $kirim[] = ['judul' => 'Bab 6'];

        app(TitleService::class)->update($t, $this->data($t), $kirim, $this->aktor());
        $this->pesanBab($t, 6, 'Alfa');
        app(ChapterAuthorService::class)->seedFromOrders($t->fresh());

        $this->assertSame([
            'Bab 1 — Hizkia', 'Bab 2 — Arif', 'Bab 3 — Rinovian',
            'Bab 4 — Laila', 'Bab 5 — Sayed', 'Bab 6 — Alfa',
        ], $this->daftarBab($t), 'Author lama harus tetap di babnya sendiri.');
    }

    // ─── keutuhan data ───

    /**
     * `$title->chapters()->delete()` memicu cascade ke tb_chapter_progress,
     * tb_title_chapter_authors, dan memutus tb_manuscript_files.title_chapter_id.
     * Menyimpan judul TIDAK boleh melakukan itu.
     *
     * @test
     */
    public function menyimpan_judul_mempertahankan_id_bab_beserta_anaknya(): void
    {
        $t = $this->buku(3);
        $idLama = $t->chapters()->pluck('id')->all();

        foreach ($t->chapters()->get() as $c) {
            $c->progress()->create(['status' => 'pembuatan', 'started_at' => now()]);
        }

        $kirim = $t->chapters()->get()->map(fn ($c) => ['id' => $c->id, 'judul' => $c->judul])->all();
        app(TitleService::class)->update($t, $this->data($t), $kirim, $this->aktor());

        $this->assertSame($idLama, $t->fresh()->chapters()->pluck('id')->all(),
            'Id bab harus bertahan — anaknya tergantung padanya.');
        $this->assertSame(3, DB::table('tb_chapter_progress')->count(),
            'Kemajuan per bab tak boleh lenyap hanya karena judul disimpan.');
    }

    /** @test */
    public function mengubah_judul_bab_tidak_membuat_baris_baru(): void
    {
        $t = $this->buku(2);
        $idLama = $t->chapters()->pluck('id')->all();

        $kirim = $t->chapters()->get()
            ->map(fn ($c) => ['id' => $c->id, 'judul' => $c->judul . ' (revisi)'])->all();
        app(TitleService::class)->update($t, $this->data($t), $kirim, $this->aktor());

        $segar = $t->fresh()->chapters()->get();
        $this->assertSame($idLama, $segar->pluck('id')->all());
        $this->assertSame(['Bab 1 (revisi)', 'Bab 2 (revisi)'], $segar->pluck('judul')->all());
    }

    /** @test */
    public function bab_yang_dibuang_dari_formulir_benar_benar_dihapus(): void
    {
        $t = $this->buku(3);

        $kirim = $t->chapters()->get()->take(2)
            ->map(fn ($c) => ['id' => $c->id, 'judul' => $c->judul])->all();
        app(TitleService::class)->update($t, $this->data($t), $kirim, $this->aktor());

        $this->assertSame(2, $t->fresh()->chapters()->count());
        $this->assertSame([1, 2], $t->fresh()->chapters()->pluck('urutan')->all());
    }

    /**
     * Menghapus bab di TENGAH lewat formulir tanpa id akan membuat sisa bab mewarisi
     * judul tetangganya. Dengan id, tiap bab tetap membawa identitasnya sendiri.
     *
     * @test
     */
    public function menghapus_bab_tengah_tidak_menggeser_identitas_bab_lain(): void
    {
        $t = $this->buku(3);
        $bab = $t->chapters()->get();
        $idBab3 = $bab[2]->id;

        // Buang bab 2, sisakan bab 1 dan bab 3.
        $kirim = [
            ['id' => $bab[0]->id, 'judul' => $bab[0]->judul],
            ['id' => $idBab3,     'judul' => $bab[2]->judul],
        ];
        app(TitleService::class)->update($t, $this->data($t), $kirim, $this->aktor());

        $segar = $t->fresh()->chapters()->get();
        $this->assertSame(['Bab 1', 'Bab 3'], $segar->pluck('judul')->all());
        $this->assertSame($idBab3, $segar[1]->id,
            'Bab 3 harus tetap bab 3 — bukan bab 2 yang berganti nama.');
        $this->assertSame([1, 2], $segar->pluck('urutan')->all(),
            'Urutan tetap rapat 1..n sesudah ada yang dibuang.');
    }

    // ─── migrasi normalisasi ───

    /** @test */
    public function migrasi_menaikkan_judul_nol_based_jadi_satu_based(): void
    {
        $nol  = $this->buku(3);
        $satu = $this->buku(3);

        // Jadikan $nol 0-based, seperti data yang lahir dari syncChapters() lama.
        foreach ($nol->chapters()->get() as $c) {
            DB::table('tb_title_chapters')->where('id', $c->id)->update(['urutan' => $c->urutan - 1]);
        }
        $this->assertSame([0, 1, 2], $nol->fresh()->chapters()->pluck('urutan')->all());

        $migrasi = include database_path('migrations/2026_08_22_000005_normalisasi_urutan_bab_satu_based.php');
        $migrasi->up();

        $this->assertSame([1, 2, 3], $nol->fresh()->chapters()->pluck('urutan')->all());
        $this->assertSame([1, 2, 3], $satu->fresh()->chapters()->pluck('urutan')->all(),
            'Judul yang sudah benar tak boleh ikut tergeser.');
    }
}
