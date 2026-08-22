<?php

namespace Tests\Feature;

use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Models\ManuscriptFile;
use App\Models\ManuscriptRevision;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\RincianTahapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Rincian per tahap untuk stepper yang bisa diklik.
 *
 * Yang dijaga di sini bukan rupa panelnya, melainkan isinya: tahap yang dikunjungi dua
 * kali harus melaporkan keduanya, dan sandi OJS tak boleh pernah bocor ke layar yang
 * terbuka untuk semua role.
 */
class RincianTahapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'd1', 'url' => 'https://drive/1']);
        });
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** @return array{0: Title, 1: TitleProgress} */
    private function naskah(string $status = 'loa'): array
    {
        $title = Title::create([
            'title' => 'Artikel Rincian', 'jenis' => 'artikel',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
        ]);
        $detail = OrderDetail::factory()->create([
            'type' => 'at_mandiri', 'title' => $title->title, 'title_id' => $title->id,
        ]);
        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'assigned_role' => TitleProgress::getHandlerForStatus($status),
            'started_at' => now(),
        ]);

        return [$title, $progress];
    }

    /**
     * `created_at` sengaja ditulis lewat forceFill: kolom itu TIDAK ada di $fillable
     * milik TitleProgressLog, jadi mengirimkannya lewat create() diam-diam dibuang dan
     * DB memakai current_timestamp() — seluruh log jadi bertanggal "sekarang" dan tiap
     * lama-tahap terhitung nol tanpa satu pun galat.
     */
    private function log(TitleProgress $p, string $dari, string $ke, array $extra = []): TitleProgressLog
    {
        $waktu = $extra['created_at'] ?? now();
        unset($extra['created_at']);

        $log = TitleProgressLog::create(array_merge([
            'title_progress_id' => $p->id,
            'event'             => 'status_advanced',
            'from_value'        => $dari,
            'to_value'          => $ke,
            'is_correction'     => false,
        ], $extra));

        $log->forceFill(['created_at' => $waktu])->save();

        return $log;
    }

    private function svc(): RincianTahapService
    {
        return app(RincianTahapService::class);
    }

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    // ─── linimasa ───

    /** @test */
    public function tahap_yang_dilewati_melaporkan_masuk_keluar_dan_pelakunya(): void
    {
        [, $p] = $this->naskah('submit');
        $rina  = User::factory()->create(['name' => 'Rina']);

        $this->log($p, 'editing', 'submit', [
            'changed_by' => $rina->id, 'created_at' => now()->subDays(9),
        ]);
        $this->log($p, 'submit', 'revisi', [
            'changed_by' => $rina->id, 'note' => 'dikirim ke JPN batch 2', 'created_at' => now(),
        ]);

        $kunjungan = $this->svc()->untuk($p)['submit']['kunjungan'];

        $this->assertCount(1, $kunjungan);
        $this->assertSame('Rina', $kunjungan[0]['oleh']);
        $this->assertSame('dikirim ke JPN batch 2', $kunjungan[0]['catatan']);
        $this->assertSame(9, $kunjungan[0]['hari']);
        $this->assertNotNull($kunjungan[0]['keluar']);
    }

    /**
     * Mundur dari LoA ke Revisi berarti tahap Revisi dijalani DUA kali. Menampilkan
     * hanya yang terakhir justru membuang riwayat yang paling menarik — persis yang
     * ingin dilihat orang saat mengklik tahapnya.
     *
     * @test
     */
    public function tahap_yang_dikunjungi_dua_kali_melaporkan_keduanya(): void
    {
        [, $p] = $this->naskah('revisi');

        $this->log($p, 'submit', 'revisi', ['created_at' => now()->subDays(10)]);
        $this->log($p, 'revisi', 'loa', ['created_at' => now()->subDays(7)]);
        $this->log($p, 'loa', 'revisi', ['created_at' => now()->subDays(3), 'event' => 'status_returned']);

        $kunjungan = $this->svc()->untuk($p)['revisi']['kunjungan'];

        $this->assertCount(2, $kunjungan, 'Dua kali masuk tahap = dua baris.');
        $this->assertNotNull($kunjungan[0]['keluar'], 'Kunjungan pertama sudah berakhir.');
        $this->assertNull($kunjungan[1]['keluar'], 'Kunjungan kedua masih berjalan.');
    }

    /** @test */
    public function tahap_berjalan_dihitung_sampai_sekarang(): void
    {
        [, $p] = $this->naskah('submit');
        $this->log($p, 'editing', 'submit', ['created_at' => now()->subDays(4)]);

        $rincian = $this->svc()->untuk($p)['submit'];

        $this->assertTrue($rincian['berjalan']);
        $this->assertSame(4, $rincian['kunjungan'][0]['hari']);
    }

    /** @test */
    public function tahap_belum_dijalani_ditandai_bukan_kosong(): void
    {
        [, $p] = $this->naskah('editing');

        $rincian = $this->svc()->untuk($p)['publish'];

        $this->assertFalse($rincian['dijalani']);
        $this->assertSame([], $rincian['kunjungan']);
    }

    /** @test */
    public function perpindahan_koreksi_ditandai_berbeda(): void
    {
        [, $p] = $this->naskah('editing');
        $this->log($p, 'submit', 'editing', [
            'event' => 'status_corrected', 'is_correction' => true, 'note' => 'salah tandai',
        ]);

        $this->assertTrue($this->svc()->untuk($p)['editing']['kunjungan'][0]['koreksi']);
    }

    // ─── berkas ───

    /** @test */
    public function berkas_muncul_di_tahap_yang_sesuai_slotnya(): void
    {
        [$title, $p] = $this->naskah('loa');

        foreach (['masuk' => 'naskah-masuk.docx', 'hasil_editing' => 'hasil-edit.docx'] as $slot => $nama) {
            ManuscriptFile::create([
                'title_id' => $title->id, 'slot' => $slot, 'status' => 'selesai',
                'version' => 1, 'original_name' => $nama, 'drive_url' => 'https://drive/x',
            ]);
        }

        $rincian = $this->svc()->untuk($p);

        $this->assertSame(['naskah-masuk.docx'], $rincian['pembuatan']['berkas']->pluck('original_name')->all());
        $this->assertSame(['hasil-edit.docx'], $rincian['editing']['berkas']->pluck('original_name')->all());
        $this->assertCount(0, $rincian['loa']['berkas']);
    }

    // ─── data tahap-khusus ───

    /** @test */
    public function tahap_submit_membawa_jurnal_dan_akun_ojs(): void
    {
        [$title, $p] = $this->naskah('loa');
        $j = Journal::create(['nama' => 'Jurnal Pendidikan Nusantara']);
        JournalSubmission::create([
            'journal_id' => $j->id, 'title_id' => $title->id, 'status' => 'submitted',
            'tgl_submit' => '2026-08-03', 'ojs_akun' => 'penulis@example.com',
            'ojs_password' => 'RahasiaOJS123',
        ]);

        $data = $this->svc()->untuk($p)['submit']['data'];

        $this->assertSame('Jurnal Pendidikan Nusantara', $data['Jurnal tujuan']);
        $this->assertSame('penulis@example.com', $data['Akun OJS']);
    }

    /**
     * Halaman /naskah/{id} terbuka untuk SEMUA role — kartu Aksi disembunyikan dari
     * marketing, halamannya sendiri tidak. Direktori Jurnal punya gerbang izinnya
     * sendiri; panel ini tak boleh jadi pintu belakang yang membocorkannya.
     *
     * @test
     */
    public function sandi_ojs_tak_pernah_bocor_ke_panel_maupun_halaman(): void
    {
        [$title, $p] = $this->naskah('loa');
        $j = Journal::create(['nama' => 'Jurnal Rahasia']);
        JournalSubmission::create([
            'journal_id' => $j->id, 'title_id' => $title->id, 'status' => 'submitted',
            'ojs_akun' => 'penulis@example.com', 'ojs_password' => 'RahasiaOJS123',
        ]);

        $keluaran = json_encode($this->svc()->untuk($p));
        $this->assertStringNotContainsString('RahasiaOJS123', $keluaran,
            'Sandi OJS tak boleh ada di keluaran service.');

        $marketing = User::factory()->create();
        $marketing->assignRole('marketing');

        $halaman = $this->actingAs($marketing)
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('RahasiaOJS123', $halaman,
            'Sandi OJS tak boleh terkirim ke peramban siapa pun lewat layar naskah.');
    }

    /** @test */
    public function tahap_revisi_membawa_jumlah_putarannya(): void
    {
        [$title, $p] = $this->naskah('revisi');
        ManuscriptRevision::create([
            'title_id' => $title->id, 'round' => 1, 'stage' => 'revisi',
            'from_stage' => 'submit', 'request_note' => 'Metodologi diperjelas',
        ]);

        $data = $this->svc()->untuk($p)['revisi']['data'];

        $this->assertArrayHasKey('Putaran perbaikan', $data);
        $this->assertStringContainsString('1', $data['Putaran perbaikan']);
    }

    // ─── tampilan ───

    /** @test */
    public function tiap_tahap_dirender_sebagai_tombol_yang_bisa_diklik(): void
    {
        [, $p] = $this->naskah('editing');

        $isi = $this->actingAs($this->superadmin())
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-tahap="editing"', $isi);
        $this->assertStringContainsString('Klik tahap untuk melihat rinciannya', $isi);
        $this->assertMatchesRegularExpression('/<button[^>]*data-tahap="submit"/', $isi,
            'Tahap harus berupa <button> supaya papan ketik dan pembaca layar ikut bekerja.');
    }

    /**
     * Kartu yang menahan laju harus berada di atas tombol yang tertahan. Sebelumnya ia
     * duduk di dasar kolom kiri sementara pesan penolakannya muncul di kolom kanan.
     *
     * @test
     */
    public function putaran_perbaikan_berada_sebelum_kartu_aksi(): void
    {
        [$title, $p] = $this->naskah('revisi');
        ManuscriptRevision::create([
            'title_id' => $title->id, 'round' => 1, 'stage' => 'revisi',
            'from_stage' => 'submit', 'request_note' => 'Perlu diperbaiki',
        ]);

        $isi = $this->actingAs($this->superadmin())
            ->get(route('naskah.show', $p->order_detail_id))
            ->assertOk()->getContent();

        $posPutaran = strpos($isi, 'Putaran Perbaikan');
        $posAksi    = strpos($isi, 'Selesaikan ');

        $this->assertNotFalse($posPutaran);
        $this->assertNotFalse($posAksi);
        $this->assertLessThan($posAksi, $posPutaran,
            'Kartu yang menahan laju harus dibaca lebih dulu daripada tombol yang tertahan.');
    }
}
