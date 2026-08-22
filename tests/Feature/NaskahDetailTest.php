<?php
// tests/Feature/NaskahDetailTest.php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\ManuscriptFile;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Layar 3 / 3B — Detail Naskah, halaman kanonik tempat semua aksi terjadi.
 * Yang dijaga: satu tombol maju (bukan dropdown semua-tahap), koreksi terkunci
 * ke superadmin, bab tanpa author benar-benar tak bisa didistribusikan, dan
 * upload naskah memicu maju tahap secara end-to-end lewat HTTP.
 */
class NaskahDetailTest extends TestCase
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

    private function naskah(string $status = 'editing', array $attrs = [], string $type = 'at_mandiri'): TitleProgress
    {
        $jenis = str_starts_with($type, 'bk_') ? 'buku' : 'artikel';
        $title = Title::create(['title' => 'Naskah Uji Detail', 'jenis' => $jenis,
                                'tipe_naskah' => $type === 'bk_kolab' ? 'kolaborasi' : 'mandiri',
                                'status' => 'disetujui']);
        $order  = Order::factory()->create(['user_id' => $this->user('marketing')->id]);
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => $type,
            'title' => $title->title, 'title_id' => $title->id, 'chapters' => 2,
        ]);

        return TitleProgress::create(array_merge([
            'order_detail_id' => $detail->id, 'status' => $status,
            'assigned_role' => TitleProgress::getHandlerForStatus($status),
            'bidang' => $jenis === 'buku' ? 'buku' : 'artikel', 'started_at' => now(),
        ], $attrs, ['order_detail_id' => $detail->id]));
    }

    /** @test */
    public function hanya_ada_satu_tombol_maju_dan_tanpa_dropdown_semua_tahap(): void
    {
        $admin = $this->user('admin', 'artikel');
        $p     = $this->naskah('editing', ['pj_user_id' => $admin->id]);

        $isi = $this->actingAs($admin)->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();

        // Satu tombol maju, labelnya menyebut tahap sekarang DAN tahap berikutnya.
        $this->assertSame(1, substr_count($isi, 'Selesaikan Editing → lanjut ke Submit'));
        // Dropdown semua-tahap hanya boleh ada di form koreksi, yang admin tak lihat.
        $this->assertStringNotContainsString('name="status"', $isi);
    }

    /** @test */
    public function admin_memajukan_tahap_satu_langkah_lewat_http(): void
    {
        $admin = $this->user('admin', 'artikel');
        $p     = $this->naskah('editing', ['pj_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('naskah.selesaikan', $p->order_detail_id), ['note' => 'sudah rapi'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('submit', $p->fresh()->status);
    }

    /** @test */
    public function flash_menyebut_jumlah_order_saat_judul_bergrup(): void
    {
        $admin = $this->user('admin', 'artikel');
        $p     = $this->naskah('editing', ['pj_user_id' => $admin->id]);
        $title = $p->orderDetail->titleRef;

        foreach (range(1, 2) as $i) {
            $order  = Order::factory()->create(['user_id' => $this->user('marketing')->id]);
            $detail = OrderDetail::factory()->create([
                'order_id' => $order->id, 'type' => 'at_mandiri',
                'title' => $title->title, 'title_id' => $title->id,
            ]);
            TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => 'editing',
                'assigned_role' => 'production', 'bidang' => 'artikel', 'started_at' => now(),
            ]);
        }

        $this->actingAs($admin)
            ->post(route('naskah.selesaikan', $p->order_detail_id))
            ->assertSessionHas('success', fn (string $pesan) => str_contains($pesan, '3 order'));
    }

    /** @test */
    public function produksi_tidak_bisa_memajukan_tahap(): void
    {
        $p = $this->naskah('editing');

        $this->actingAs($this->user('production'))
            ->post(route('naskah.selesaikan', $p->order_detail_id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('editing', $p->fresh()->status);
    }

    /** @test */
    public function koreksi_tanpa_catatan_ditolak_dengan_pesan_indonesia(): void
    {
        $p = $this->naskah('submit');

        $this->actingAs($this->user('superadmin'))
            ->post(route('naskah.koreksi', $p->order_detail_id), ['status' => 'editing', 'note' => ''])
            ->assertRedirect()
            ->assertSessionHas('error', 'Catatan wajib diisi untuk koreksi tahap.');

        $this->assertSame('submit', $p->fresh()->status);
    }

    /** @test */
    public function submit_tanpa_perubahan_bukan_error_melainkan_info_ramah(): void
    {
        $p = $this->naskah('editing');

        $this->actingAs($this->user('superadmin'))
            ->post(route('naskah.koreksi', $p->order_detail_id), ['status' => 'editing', 'note' => 'tanpa ubah'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Tidak ada perubahan.');
    }

    /** @test */
    public function marketing_tidak_melihat_blok_aksi_tapi_tetap_bisa_set_target(): void
    {
        $mkt = $this->user('marketing');
        $p   = $this->naskah('editing');

        $this->actingAs($mkt)->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            ->assertDontSee('Selesaikan Editing')
            ->assertDontSee('Tarik tugas dari pelaksana');

        $this->actingAs($mkt)
            ->post(route('naskah.target', $p->order_detail_id), ['target_date' => '2026-09-30'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('2026-09-30', $p->fresh()->target_date->toDateString());
    }

    /** @test */
    public function upload_naskah_oleh_pelaksana_memajukan_tahap_end_to_end(): void
    {
        $pelaksana = $this->user('production');
        $pj        = $this->user('admin', 'artikel');
        $p         = $this->naskah('pembuatan', [
            'pelaksana_user_id' => $pelaksana->id,
            'pj_user_id'        => $pj->id,
            'sla_due_at'        => now()->addDays(5),
        ]);

        $this->actingAs($pelaksana)
            ->post(route('naskah.file', $p->order_detail_id), [
                'slot' => 'masuk',
                'file' => UploadedFile::fake()->create('naskah.docx', 20),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('editing', $p->fresh()->status);
        $this->assertDatabaseHas('tb_manuscript_files', ['slot' => 'masuk', 'uploaded_by' => $pelaksana->id]);
        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id, 'event' => 'auto_advance_upload',
        ]);
    }

    /** @test */
    public function pembatalan_wajib_alasan(): void
    {
        $admin = $this->user('admin', 'artikel');
        $p     = $this->naskah('editing', ['pj_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('naskah.batal', $p->order_detail_id), ['cancel_reason' => ''])
            ->assertRedirect()
            ->assertSessionHas('error', 'Alasan pembatalan wajib diisi.');

        $this->assertNull($p->fresh()->cancelled_at);

        $this->actingAs($admin)
            ->post(route('naskah.batal', $p->order_detail_id), ['cancel_reason' => 'Klien mundur'])
            ->assertSessionHas('success');

        $this->assertNotNull($p->fresh()->cancelled_at);
    }

    // ─── Layar 3B: buku kolaborasi per bab ───

    private function buku(array $babStatuses, bool $denganAuthor = true): TitleProgress
    {
        $p    = $this->naskah('pembuatan', [], 'bk_kolab');
        $book = $p->orderDetail->titleRef;

        foreach ($babStatuses as $i => $status) {
            $bab = $book->chapters()->create(['judul' => 'Bab ' . ($i + 1), 'urutan' => $i + 1]);
            if ($denganAuthor) {
                $bab->authors()->attach(Author::create(['name' => 'Dr. Author ' . $i])->id, ['position' => 1]);
            }
            $bab->progress()->create(['status' => $status, 'started_at' => now()]);
        }

        return $p;
    }

    /** @test */
    public function tabel_bab_menampilkan_author_dan_menandai_yang_belum_dipetakan(): void
    {
        $p = $this->buku(['menunggu'], denganAuthor: false);

        $this->actingAs($this->user('admin', 'buku'))
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            // Judul kolom dipadatkan jadi "Author" supaya lebar berpindah ke kolom Aksi;
            // yang dijaga tes ini adalah kolomnya ADA, bukan bunyi persisnya.
            ->assertSee('<th>Author</th>', false)
            ->assertSee('Author belum dipetakan')
            ->assertSee('Petakan Author');
    }

    /** @test */
    public function bab_tanpa_author_tidak_bisa_didistribusikan(): void
    {
        $p  = $this->buku(['menunggu'], denganAuthor: false);
        $cp = $p->orderDetail->titleRef->chapters()->first()->progress;

        $this->actingAs($this->user('admin', 'buku'))
            ->post(route('naskah.bab.distribusi', $cp->id), [
                'pelaksana_user_id' => $this->user('production')->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Petakan author bab terlebih dahulu.');

        $this->assertNull($cp->fresh()->pelaksana_user_id);
    }

    /** @test */
    public function memetakan_author_membuka_distribusi_bab(): void
    {
        $p  = $this->buku(['menunggu'], denganAuthor: false);
        $cp = $p->orderDetail->titleRef->chapters()->first()->progress;
        $admin = $this->user('admin', 'buku');

        $this->actingAs($admin)
            ->post(route('naskah.bab.author', $cp->id), ['author' => 'Rina, M.E.'])
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->post(route('naskah.bab.distribusi', $cp->id), [
                'pelaksana_user_id' => $this->user('production')->id,
            ])
            ->assertSessionHas('success');

        $this->assertSame('pembuatan', $cp->fresh()->status);
    }

    /** @test */
    public function tombol_mulai_layout_terkunci_sampai_semua_bab_selesai(): void
    {
        $admin = $this->user('admin', 'buku');
        $p     = $this->buku(['selesai', 'editing']);
        $p->update(['status' => 'editing', 'pj_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('naskah.selesaikan', $p->order_detail_id))
            ->assertSessionHas('error', 'Semua bab harus Selesai dulu sebelum masuk tahap Layout.');

        // Selesaikan bab terakhir → gerbang terbuka.
        // Relasi chapters() sudah mengurutkan naik; urutkan di koleksi, bukan di query.
        $babTerakhir = $p->orderDetail->titleRef->chapters()->get()->sortByDesc('urutan')->first();
        $this->actingAs($admin)
            ->post(route('naskah.bab.selesaikan', $babTerakhir->progress->id))
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->post(route('naskah.selesaikan', $p->order_detail_id))
            ->assertSessionHas('success');

        $this->assertSame('layout', $p->fresh()->status);
    }

    /** @test */
    public function pelaksana_bab_punya_tombol_unggah_di_barisnya_sendiri(): void
    {
        $pelaksana = $this->user('production');
        $p         = $this->buku(['pembuatan']);
        $bab       = $p->orderDetail->titleRef->chapters()->first();
        $bab->progress->update(['pelaksana_user_id' => $pelaksana->id]);

        $this->actingAs($pelaksana)->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            ->assertSee(route('naskah.bab.file', $bab->progress->id), false)
            ->assertSee('Upload Naskah');
    }

    /** @test */
    public function file_bab_yang_sudah_diunggah_bisa_dibuka_dari_tabel_bab(): void
    {
        $pelaksana = $this->user('production');
        $p         = $this->buku(['pembuatan']);
        $bab       = $p->orderDetail->titleRef->chapters()->first();
        $bab->progress->update(['pelaksana_user_id' => $pelaksana->id]);

        $this->actingAs($pelaksana)->post(route('naskah.bab.file', $bab->progress->id), [
            'slot' => 'masuk',
            'file' => UploadedFile::fake()->create('bab1.docx', 12),
        ])->assertSessionHas('success');

        $this->actingAs($this->user('admin', 'buku'))
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            ->assertSee('Naskah Masuk v1');
    }

    /** @test */
    public function header_buku_kolaborasi_menjelaskan_roll_up_dan_jumlah_author(): void
    {
        $p = $this->buku(['pembuatan', 'menunggu']);

        $this->actingAs($this->user('admin', 'buku'))
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            ->assertSee('roll-up otomatis')
            ->assertSee('2 bab · 2 author')
            ->assertSee('Riwayat Lengkap');
    }

    /** @test */
    public function kartu_informasi_menampilkan_status_pembayaran(): void
    {
        $p = $this->naskah('editing');

        $this->actingAs($this->user('admin', 'artikel'))
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            ->assertSee('Pembayaran')
            ->assertSee('Pelunasan:');
    }

    /** @test */
    public function terapkan_satu_pelaksana_ke_semua_bab_melewati_bab_tanpa_author(): void
    {
        $admin     = $this->user('admin', 'buku');
        $pelaksana = $this->user('production');
        $p         = $this->buku(['menunggu', 'menunggu']);
        $book      = $p->orderDetail->titleRef;

        // Order helper ini bernaskah 'dibuatkan' dan bernomor bab di luar 1–3, jadi
        // ketiga bab belum terpesan → tak ada yang bernaskah mandiri.
        $p->orderDetail->update(['naskah_type' => 'dibuatkan']);

        // Satu bab tambahan tanpa author — harus dilewati, bukan menggagalkan semuanya.
        $tanpaAuthor = $book->chapters()->create(['judul' => 'Bab 3', 'urutan' => 3]);
        $tanpaAuthor->progress()->create(['status' => 'menunggu', 'started_at' => now()]);

        $this->actingAs($admin)
            ->post(route('naskah.bab.pelaksanaSemua', $p->order_detail_id), [
                'pelaksana_user_id' => $pelaksana->id,
            ])
            ->assertSessionHas('success', fn (string $pesan) => str_contains($pesan, '2 bab')
                && str_contains($pesan, 'author belum dipetakan'));

        $bab = $book->chapters()->with('progress')->get()->sortBy('urutan')->values();
        $this->assertSame($pelaksana->id, $bab[0]->progress->pelaksana_user_id);
        $this->assertSame($pelaksana->id, $bab[1]->progress->pelaksana_user_id);
        $this->assertNull($bab[2]->progress->fresh()->pelaksana_user_id);
    }

    /** @test */
    public function struktur_bab_bisa_ditambah_dan_judulnya_diubah(): void
    {
        $admin = $this->user('admin', 'buku');
        $p     = $this->buku(['menunggu']);
        $book  = $p->orderDetail->titleRef;
        $bab1  = $book->chapters()->first();

        $this->actingAs($admin)
            ->post(route('naskah.bab.struktur', $p->order_detail_id), [
                'judul'  => [$bab1->id => 'Pengantar Ekonomi Digital'],
                'tambah' => 2,
            ])
            ->assertSessionHas('success');

        $this->assertSame('Pengantar Ekonomi Digital', $bab1->fresh()->judul);
        $this->assertSame(3, $book->chapters()->count());
        // Bab baru langsung punya progress supaya muncul di tabel & antrian.
        $this->assertSame(3, \App\Models\ChapterProgress::count());
    }

    /** @test */
    public function bab_yang_sudah_dikerjakan_tidak_bisa_dihapus_lewat_struktur(): void
    {
        $admin     = $this->user('admin', 'buku');
        $pelaksana = $this->user('production');
        $p         = $this->buku(['menunggu', 'menunggu']);
        $book      = $p->orderDetail->titleRef;
        $bab       = $book->chapters()->get()->sortBy('urutan')->values();

        $bab[1]->progress->update(['pelaksana_user_id' => $pelaksana->id, 'status' => 'pembuatan']);

        $this->actingAs($admin)
            ->post(route('naskah.bab.struktur', $p->order_detail_id), [
                'hapus' => [$bab[0]->id, $bab[1]->id],
            ])
            ->assertSessionHas('success', fn (string $pesan) => str_contains($pesan, '1 bab tidak dihapus'));

        $this->assertNull($book->chapters()->find($bab[0]->id), 'Bab yang belum tersentuh boleh dihapus.');
        $this->assertNotNull($book->chapters()->find($bab[1]->id), 'Bab yang sudah dikerjakan harus bertahan.');
    }

    /** @test */
    public function produksi_tidak_boleh_mengubah_struktur_bab(): void
    {
        $p = $this->buku(['menunggu']);

        $this->actingAs($this->user('production'))
            ->post(route('naskah.bab.struktur', $p->order_detail_id), ['tambah' => 5])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, $p->orderDetail->titleRef->chapters()->count());
    }

    /**
     * Slot file mengikuti jenis naskah. Artikel butuh LoA (surat penerimaan dari jurnal)
     * dan tak pernah punya layout/proofread/cover; buku sebaliknya. Menampilkan semuanya
     * di kedua jenis hanya melahirkan baris "belum ada" yang tak akan pernah terisi.
     *
     * @test
     */
    public function artikel_punya_slot_loa_dan_tanpa_slot_khas_buku(): void
    {
        $p = $this->naskah('submit');

        $this->actingAs($this->user('admin', 'artikel'))
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            ->assertSee('LoA (Letter of Acceptance)')
            ->assertDontSee('Hasil Layout')
            ->assertDontSee('Hasil Proofread');
    }

    /** @test */
    public function buku_punya_slot_layout_cover_tanpa_slot_loa(): void
    {
        $p = $this->naskah('layout', [], 'bk_mandiri');

        $this->actingAs($this->user('admin', 'buku'))
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            ->assertSee('Hasil Layout')
            ->assertSee('Cover')
            ->assertDontSee('LoA (Letter of Acceptance)');
    }

    /** @test */
    public function file_loa_artikel_benar_benar_tersimpan(): void
    {
        $p = $this->naskah('loa');

        $this->actingAs($this->user('admin', 'artikel'))
            ->post(route('naskah.file', $p->order_detail_id), [
                'slot' => 'loa',
                'file' => UploadedFile::fake()->create('loa-jurnal.pdf', 12),
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('tb_manuscript_files', [
            'title_id' => $p->orderDetail->title_id,
            'slot'     => 'loa',
        ]);
    }

    /**
     * Asal naskah tiap bab datang dari ORDER yang memesan bab itu — pada buku kolaborasi
     * `order_details.chapters` menyimpan nomor babnya. Tabel bab harus menyebutnya apa
     * adanya: bab bernaskah mandiri tak boleh terbaca sebagai "belum ditugaskan", karena
     * itulah yang membuat pelaksana menulis naskah yang sudah dikirim authornya.
     *
     * @test
     */
    public function tabel_bab_menyebut_asal_naskah_sesuai_ordernya(): void
    {
        $book = \App\Models\Title::create(['title' => 'Kolab Campur', 'jenis' => 'buku',
                                           'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);

        // Bab 1 dibuatkan tim, Bab 2 dikirim authornya sendiri.
        $pertama = null;
        foreach ([[1, 'dibuatkan'], [2, 'mandiri']] as [$nomor, $jenis]) {
            $order  = \App\Models\Order::factory()->create(['user_id' => $this->user('marketing')->id]);
            $detail = OrderDetail::factory()->create([
                'order_id' => $order->id, 'type' => 'bk_kolab',
                'title' => $book->title, 'title_id' => $book->id,
                'chapters' => $nomor, 'naskah_type' => $jenis,
            ]);
            $detail->authors()->attach(Author::create(['name' => 'Penulis ' . $nomor])->id, ['position' => 1]);
            \App\Models\TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => 'pembuatan',
                'assigned_role' => 'production', 'bidang' => 'buku', 'started_at' => now(),
            ]);
            $pertama ??= $detail;

            $bab = $book->chapters()->create(['judul' => 'Bab ' . $nomor, 'urutan' => $nomor]);
            $bab->progress()->create(['status' => 'menunggu', 'started_at' => now()]);
        }

        app(\App\Services\ChapterAuthorService::class)->seedFromOrders($book);

        $bab = $book->chapters()->with('progress')->get()->sortBy('urutan')->values();
        $this->assertSame('dibuatkan', $bab[0]->progress->sumberNaskah());
        $this->assertSame('mandiri', $bab[1]->progress->sumberNaskah());

        $isi = $this->actingAs($this->user('admin', 'buku'))
            ->get(route('naskah.show', $pertama->id))->assertOk()->getContent();

        $this->assertStringContainsString('Naskah Mandiri', $isi);
        $this->assertStringContainsString('Belum ditugaskan', $isi);
        // Bab mandiri menawarkan unggahan naskah author, BUKAN distribusi ke pelaksana.
        $this->assertStringContainsString('Naskah dari Author', $isi);
    }

    /**
     * Chip jenis naskah di header duduk sejajar jenis & jumlah bab, jadi terbaca sebagai
     * sifat JUDUL. Kalau order sejudul tidak seragam, chip itu disembunyikan — lebih baik
     * diam daripada menyebut satu jenis yang tak berlaku untuk order lainnya.
     *
     * @test
     */
    public function chip_jenis_naskah_disembunyikan_saat_order_sejudul_tidak_seragam(): void
    {
        $book = \App\Models\Title::create(['title' => 'Kolab Tak Seragam', 'jenis' => 'buku',
                                           'tipe_naskah' => 'kolaborasi', 'status' => 'disetujui']);

        $mandiri = null;
        foreach ([[1, 'dibuatkan'], [2, 'mandiri']] as [$nomor, $jenis]) {
            $order  = \App\Models\Order::factory()->create(['user_id' => $this->user('marketing')->id]);
            $detail = OrderDetail::factory()->create([
                'order_id' => $order->id, 'type' => 'bk_kolab',
                'title' => $book->title, 'title_id' => $book->id,
                'chapters' => $nomor, 'naskah_type' => $jenis,
            ]);
            \App\Models\TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => 'pembuatan',
                'assigned_role' => 'production', 'bidang' => 'buku', 'started_at' => now(),
            ]);
            if ($jenis === 'mandiri') {
                $mandiri = $detail;
            }
        }

        // Dibuka dari order yang justru bernaskah mandiri — chip tetap tak boleh muncul.
        $isi = $this->actingAs($this->user('admin', 'buku'))
            ->get(route('naskah.show', $mandiri->id))->assertOk()->getContent();

        // Header = bagian sebelum judul besar; di situlah chip jenis naskah berada.
        $header = substr($isi, 0, strpos($isi, 'Kolab Tak Seragam'));
        $this->assertStringNotContainsString('Naskah Mandiri', $header,
            'Header tidak boleh mengklaim jenis naskah saat order sejudul tidak seragam.');
        $this->assertStringNotContainsString('Naskah Dibuatkan', $header);

        // Sebagai gantinya banner grup mengaku tidak seragam, dan kartu Informasi
        // menegaskan jenis yang disebutnya hanya berlaku untuk order yang dibuka.
        $this->assertStringContainsString('Jenis naskahnya tidak seragam', $isi);
        $this->assertStringContainsString('Naskah Mandiri (order ini)', $isi);
    }

    /** @test */
    public function chip_jenis_naskah_tampil_saat_seluruh_order_sejudul_sepakat(): void
    {
        $p = $this->naskah('editing');
        $p->orderDetail->update(['naskah_type' => 'dibuatkan']);

        $this->actingAs($this->user('admin', 'artikel'))
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            ->assertSee('Naskah Dibuatkan');
    }

    /** @test */
    public function produksi_bisa_mengambil_bab_langsung_dari_tabel_bab(): void
    {
        $me = $this->user('production');
        $p  = $this->buku(['menunggu']);
        $cp = $p->orderDetail->titleRef->chapters()->first()->progress;

        $this->actingAs($me)->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()
            ->assertSee('Ambil Bab Ini');

        $this->actingAs($me)
            ->post(route('naskah.bab.claim', $cp->id))
            ->assertSessionHas('success');

        $cp->refresh();
        $this->assertSame($me->id, $cp->pelaksana_user_id);
        $this->assertSame('pembuatan', $cp->status);
    }

    /** @test */
    public function unggahan_yang_ditolak_validasi_memberi_pesan_bukan_diam_diam_gagal(): void
    {
        $pelaksana = $this->user('production');
        $p         = $this->naskah('pembuatan', ['pelaksana_user_id' => $pelaksana->id]);

        // Format di luar pdf/doc/docx/zip → ditolak validasi. Yang diuji: pesannya
        // benar-benar sampai ke pengguna, bukan halaman termuat ulang tanpa keterangan.
        $this->actingAs($pelaksana)
            ->post(route('naskah.file', $p->order_detail_id), [
                'slot' => 'masuk',
                'file' => UploadedFile::fake()->create('gambar.png', 10),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame('pembuatan', $p->fresh()->status);
    }

    /** @test */
    public function file_bab_tersimpan_terpisah_dari_file_level_buku(): void
    {
        $pelaksana = $this->user('production');
        $p         = $this->buku(['pembuatan']);
        $bab       = $p->orderDetail->titleRef->chapters()->first();
        $bab->progress->update(['pelaksana_user_id' => $pelaksana->id]);

        $this->actingAs($pelaksana)
            ->post(route('naskah.bab.file', $bab->progress->id), [
                'slot' => 'masuk',
                'file' => UploadedFile::fake()->create('bab1.docx', 15),
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('tb_manuscript_files', [
            'title_chapter_id' => $bab->id, 'slot' => 'masuk',
        ]);
        // Upload naskah bab oleh pelaksananya memajukan bab itu sendiri.
        $this->assertSame('editing', $bab->progress->fresh()->status);
        $this->assertSame(0, ManuscriptFile::whereNull('title_chapter_id')->count());
    }
}
