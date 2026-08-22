<?php

namespace Tests\Feature;

use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Menyambungkan Pelacakan Naskah dengan Direktori Jurnal untuk naskah artikel.
 *
 * Sebelumnya keduanya berjalan sendiri-sendiri: tahap artikel bergerak
 * submit → loa → publish di Pelacakan, sementara tgl_submit / link_publish hidup
 * di tb_journal_submissions yang diisi dari modul jurnal. Tak ada yang
 * menghubungkan, jadi 15 artikel di data produksi sampai ke tahap jurnal dengan
 * NOL catatan submission.
 *
 * Sekarang datanya direbut di tempat orang benar-benar bekerja — tombol
 * "Selesaikan tahap" di Detail Naskah.
 */
class NaskahJurnalTest extends TestCase
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

        return $u->fresh();
    }

    private function naskah(string $status, string $type = 'at_mandiri'): TitleProgress
    {
        $jenis = str_starts_with($type, 'bk_') ? 'buku' : 'artikel';
        $title = Title::create([
            'title' => 'Artikel Jurnal ' . fake()->unique()->word(),
            'jenis' => $jenis, 'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
        ]);
        $order  = Order::factory()->create(['user_id' => $this->user('marketing')->id]);
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => $type,
            'title' => $title->title, 'title_id' => $title->id, 'chapters' => 1,
        ]);

        return TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'assigned_role' => TitleProgress::getHandlerForStatus($status),
            'bidang' => $jenis, 'started_at' => now(),
        ]);
    }

    // ─── mencatat saat tahap diselesaikan ───

    /** @test */
    public function menyelesaikan_submit_mencatat_jurnal_dan_tanggalnya(): void
    {
        $p = $this->naskah('submit');

        $this->actingAs($this->user('superadmin'))
            ->post(route('naskah.selesaikan', $p->order_detail_id), [
                'nama_jurnal' => 'Jurnal Pendidikan Nusantara',
                'tgl_submit'  => '2026-08-01',
                'ojs_akun'    => 'penulis@example.com',
            ])->assertRedirect();

        $sub = JournalSubmission::first();
        $this->assertNotNull($sub, 'Menyelesaikan tahap Submit harus meninggalkan catatan submission.');
        $this->assertSame('2026-08-01', $sub->tgl_submit->format('Y-m-d'));
        $this->assertSame('submitted', $sub->status);
        $this->assertSame($p->orderDetail->title_id, $sub->title_id);
    }

    /**
     * Direktori Jurnal kosong (0 baris di produksi) dan journal_id NOT NULL, jadi
     * mewajibkan memilih dari direktori berarti tak seorang pun bisa menyelesaikan
     * tahap Submit sampai direktorinya diisi lebih dulu. Mengikuti pola yang sudah
     * dipakai kodebase untuk Scope: nama yang diketik jadi baris master.
     *
     * @test
     */
    public function nama_jurnal_baru_otomatis_masuk_direktori_jurnal(): void
    {
        $p = $this->naskah('submit');

        $this->actingAs($this->user('superadmin'))
            ->post(route('naskah.selesaikan', $p->order_detail_id), [
                'nama_jurnal' => 'Jurnal Sains Terapan',
                'tgl_submit'  => '2026-08-02',
            ])->assertRedirect();

        $this->assertDatabaseHas('tb_journals', ['nama' => 'Jurnal Sains Terapan']);
        $this->assertSame(Journal::first()->id, JournalSubmission::first()->journal_id);
    }

    /** @test */
    public function jurnal_yang_sudah_ada_dipakai_ulang_bukan_diduplikasi(): void
    {
        $jurnal = Journal::create(['nama' => 'Jurnal Sudah Ada']);
        $p      = $this->naskah('submit');

        $this->actingAs($this->user('superadmin'))
            ->post(route('naskah.selesaikan', $p->order_detail_id), [
                'journal_id' => $jurnal->id,
                'tgl_submit' => '2026-08-03',
            ])->assertRedirect();

        $this->assertSame(1, Journal::count(), 'Memilih jurnal yang ada tak boleh membuat baris kembar.');
        $this->assertSame($jurnal->id, JournalSubmission::first()->journal_id);
    }

    /** @test */
    public function menyelesaikan_loa_mengisi_link_terbit_di_submission_yang_sama(): void
    {
        $super = $this->user('superadmin');
        $p     = $this->naskah('submit');

        $this->actingAs($super)->post(route('naskah.selesaikan', $p->order_detail_id), [
            'nama_jurnal' => 'Jurnal Alur Penuh', 'tgl_submit' => '2026-08-01',
        ])->assertRedirect();

        // Revisi kini duduk di antara Submit dan LoA. Tak ada permintaan revisi di alur
        // ini, jadi tahapnya cukup dilewati — persis yang dilakukan orang saat reviewer
        // tak meminta apa-apa.
        $p->refresh();
        $this->actingAs($super)->post(route('naskah.selesaikan', $p->order_detail_id))
            ->assertRedirect();

        // Publish adalah tahap FINAL, jadi tak pernah "diselesaikan": link terbit
        // direbut saat menyelesaikan LoA, transisi yang MASUK ke publish.
        $p->refresh();
        $this->actingAs($super)->post(route('naskah.selesaikan', $p->order_detail_id), [
            'link_publish' => 'https://jurnal.example.com/artikel/1',
            'tgl_terbit'   => '2026-08-20',
        ])->assertRedirect();

        $this->assertSame(1, JournalSubmission::count(), 'Satu naskah = satu baris submission, bukan tiga.');
        $sub = JournalSubmission::first();
        $this->assertSame('https://jurnal.example.com/artikel/1', $sub->link_publish);
        $this->assertSame('published', $sub->status);
    }

    // ─── batas ───

    /** @test */
    public function naskah_buku_tak_pernah_membuat_submission_jurnal(): void
    {
        $p = $this->naskah('isbn', 'bk_mandiri');

        $this->actingAs($this->user('superadmin'))
            ->post(route('naskah.selesaikan', $p->order_detail_id), [
                'nama_jurnal' => 'Jurnal Salah Alamat',
            ])->assertRedirect();

        $this->assertSame(0, JournalSubmission::count());
        $this->assertSame(0, Journal::count(), 'Buku tak boleh diam-diam menambah baris Direktori Jurnal.');
    }

    /**
     * Validasi mewajibkan nama_jurnal / link_publish. Kalau kolomnya tak ada di
     * formulir, alur artikel jadi buntu: tombol Selesaikan ditolak terus dan tak ada
     * tempat mengisi apa yang diminta. Tes ini mengunci keduanya tetap sejalan.
     *
     * @test
     */
    public function formulir_meminta_data_jurnal_di_tahap_yang_membutuhkannya(): void
    {
        $super = $this->user('superadmin');

        $submit = $this->actingAs($super)
            ->get(route('naskah.show', $this->naskah('submit')->order_detail_id))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Jurnal tujuan', $submit);
        $this->assertStringContainsString('name="nama_jurnal"', $submit);

        $loa = $this->actingAs($super)
            ->get(route('naskah.show', $this->naskah('loa')->order_detail_id))
            ->assertOk()->getContent();
        $this->assertStringContainsString('name="link_publish"', $loa);
    }

    /** @test */
    public function formulir_buku_tak_pernah_meminta_data_jurnal(): void
    {
        $isi = $this->actingAs($this->user('superadmin'))
            ->get(route('naskah.show', $this->naskah('isbn', 'bk_mandiri')->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('name="nama_jurnal"', $isi);
        $this->assertStringNotContainsString('name="link_publish"', $isi);
    }

    /**
     * Judul yang linknya SUDAH tercatat (mis. diisi lewat Direktori Judul) tak boleh
     * diminta mengisinya lagi. Sempat terlanggar: validasi baru menuntut link_publish
     * tanpa syarat, dan NotificationHooksTest langsung merah karena naskah yang
     * linknya sudah ada jadi tak bisa maju sama sekali.
     *
     * @test
     */
    public function link_tak_diminta_lagi_bila_judulnya_sudah_punya(): void
    {
        $p = $this->naskah('loa');
        $p->orderDetail->titleRef->update(['link_terbit' => 'https://sudah.ada/artikel']);

        $this->actingAs($this->user('superadmin'))
            ->post(route('naskah.selesaikan', $p->order_detail_id))
            ->assertSessionHasNoErrors();

        $this->assertSame('publish', $p->fresh()->status);
    }

    // ─── tampilan ───

    /** @test */
    public function detail_naskah_artikel_menampilkan_jurnal_dan_menautkan_ke_direktori(): void
    {
        $super = $this->user('superadmin');
        $p     = $this->naskah('submit');

        $this->actingAs($super)->post(route('naskah.selesaikan', $p->order_detail_id), [
            'nama_jurnal' => 'Jurnal Tampil', 'tgl_submit' => '2026-08-05',
        ])->assertRedirect();

        $isi = $this->actingAs($super)->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Jurnal Tampil', $isi);
        $this->assertStringContainsString(route('journal.show', Journal::first()->id), $isi,
            'Detail Naskah harus menautkan ke Direktori Jurnal.');
        $this->assertStringContainsString(route('title.show', $p->orderDetail->title_id), $isi,
            'Detail Naskah harus menautkan ke Direktori Judul.');
    }
}
