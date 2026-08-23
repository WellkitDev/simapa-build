<?php
// tests/Feature/AntreanTakMacetTest.php

namespace Tests\Feature;

use App\Jobs\UnggahBerkasKeDrive;
use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Antrean produksi digerakkan SATU cron `schedule:run` yang berjalan tiap menit.
 * Susunan itu punya tiga cara membeku tanpa satu pun pesan galat — ketiganya pernah
 * terlihat sebagai "berkas sedang diunggah…" yang tak pernah selesai.
 *
 * Tes ini menjaga ketiganya sekaligus, karena tak satu pun bisa terlihat dari
 * membaca kode: semuanya hanya muncul sebagai keheningan di produksi.
 */
class AntreanTakMacetTest extends TestCase
{
    use RefreshDatabase;

    private function eventQueueWork(): Event
    {
        foreach (app(Schedule::class)->events() as $e) {
            if (str_contains((string) $e->command, 'queue:work')) {
                return $e;
            }
        }

        $this->fail('queue:work tidak terdaftar di scheduler — seluruh antrean mati.');
    }

    /**
     * Cara membeku #1, yang paling berbahaya.
     *
     * `withoutOverlapping()` tanpa argumen memasang kunci yang baru kedaluwarsa
     * 1.440 menit — DUA PULUH EMPAT JAM. Satu worker yang mati tanpa sempat melepas
     * kuncinya (proses dibunuh hosting, unggahan yang menggantung, deploy di tengah
     * jalan) karena itu membekukan SELURUH antrean sehari penuh: cron tetap berbunyi
     * tiap menit, tetap dilewati diam-diam, dan tak ada yang tercatat di mana pun.
     *
     * @test
     */
    public function kunci_anti_tumpang_tindih_kedaluwarsa_dalam_hitungan_menit(): void
    {
        $e = $this->eventQueueWork();

        $this->assertTrue($e->withoutOverlapping, 'Tanpa ini dua worker berebut job yang sama.');
        $this->assertLessThanOrEqual(15, $e->expiresAt,
            'Kunci yang berumur panjang mengubah satu worker yang mati jadi antrean beku berjam-jam.');
    }

    /**
     * Cara membeku #2: worker membunuh jobnya sendiri.
     *
     * Bawaan `queue:work` adalah `--timeout=60`. Mengirim berkas 20 MB ke Google Drive
     * dari hosting bersama lazim melewatinya, jadi jobnya dibunuh berulang kali sampai
     * habis percobaan — padahal tak ada yang salah selain waktunya kurang.
     *
     * @test
     */
    public function worker_diberi_waktu_cukup_untuk_unggahan_besar(): void
    {
        $perintah = (string) $this->eventQueueWork()->command;

        $this->assertMatchesRegularExpression('/--timeout=(\d+)/', $perintah,
            'Tanpa --timeout eksplisit berlaku bawaan 60 detik.');

        preg_match('/--timeout=(\d+)/', $perintah, $m);
        $this->assertGreaterThanOrEqual(180, (int) $m[1],
            'Unggahan 20 MB ke Drive dari hosting bersama butuh lebih dari tiga menit.');
    }

    /**
     * Cara membeku #3: dua worker mengerjakan job yang sama.
     *
     * `retry_after` menentukan berapa lama sebuah job dianggap masih dikerjakan. Bila
     * ia LEBIH PENDEK dari batas waktu worker, job yang masih berjalan dianggap
     * terbengkalai dan diambil worker kedua — berkas yang sama terunggah dua kali.
     *
     * @test
     */
    public function retry_after_selalu_lebih_panjang_dari_batas_waktu_worker(): void
    {
        preg_match('/--timeout=(\d+)/', (string) $this->eventQueueWork()->command, $m);
        $timeout = (int) ($m[1] ?? 60);

        $this->assertGreaterThan(
            $timeout,
            (int) config('queue.connections.database.retry_after'),
            'retry_after lebih pendek dari timeout → job yang masih jalan diambil worker kedua.'
        );
    }

    /** Berkas naskah + salinan lokalnya, dengan umur yang bisa diatur. */
    private function berkasAntre(int $umurMenit, string $status = 'antre'): ManuscriptFile
    {
        $title = Title::create([
            'title' => 'Buku ' . fake()->unique()->word(), 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
        ]);

        $path = 'unggahan-antre/' . fake()->unique()->uuid() . '.pdf';
        Storage::disk('local')->put($path, 'isi');

        $b = ManuscriptFile::create([
            'title_id' => $title->id, 'title_chapter_id' => null, 'slot' => 'ebook',
            'status' => $status, 'version' => 1, 'original_name' => 'KEUANGAN.pdf',
            'local_path' => $path, 'uploaded_by' => User::factory()->create()->id,
        ]);

        // created_at tak ada di $fillable, jadi harus dipaksa.
        $b->forceFill(['created_at' => now()->subMinutes($umurMenit)])->save();

        return $b->fresh();
    }

    /**
     * Jaring pengaman terakhir. Ketika sebuah job hilang tanpa jejak — worker dibunuh
     * di tengah unggahan, jadi `failed()` tak pernah berjalan dan statusnya tak pernah
     * jadi `gagal` — barisnya tertinggal di `antre` SELAMANYA. Di server tanpa terminal
     * tak ada seorang pun yang bisa membangunkannya kembali.
     *
     * @test
     */
    public function unggahan_yang_tertinggal_lama_dibangkitkan_lagi(): void
    {
        Storage::fake('local');
        Queue::fake();

        $tertinggal = $this->berkasAntre(30);

        $this->artisan('unggahan:bangkitkan')->assertSuccessful();

        Queue::assertPushed(UnggahBerkasKeDrive::class, 1);
        $this->assertSame('antre', $tertinggal->fresh()->status, 'Statusnya belum boleh berubah; jobnya yang diulang.');
    }

    /**
     * Yang baru saja diantrekan TIDAK disentuh: jobnya kemungkinan besar masih menunggu
     * gilirannya, dan mengulangnya cuma menggandakan pekerjaan.
     *
     * @test
     */
    public function unggahan_yang_masih_baru_dibiarkan(): void
    {
        Storage::fake('local');
        Queue::fake();

        $this->berkasAntre(2);

        $this->artisan('unggahan:bangkitkan')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * `gagal` sudah punya jalannya sendiri: `failed()` mencatatnya di riwayat dan
     * mengabari orangnya. Membangkitkannya otomatis berarti mengulang kegagalan yang
     * sama tanpa henti.
     *
     * @test
     */
    public function unggahan_yang_sudah_dinyatakan_gagal_tidak_diulang_otomatis(): void
    {
        Storage::fake('local');
        Queue::fake();

        $this->berkasAntre(120, status: 'gagal');
        $this->berkasAntre(120, status: 'selesai');

        $this->artisan('unggahan:bangkitkan')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * Salinan lokalnya sudah tak ada (mis. dibersihkan `unggahan:prune`), jadi
     * mengantrekannya lagi hanya menghasilkan job yang pasti gagal. Barisnya
     * ditandai `gagal` supaya berhenti menjanjikan "sedang diunggah" selamanya.
     *
     * @test
     */
    public function baris_tanpa_salinan_lokal_ditandai_gagal_bukan_diantrekan(): void
    {
        Storage::fake('local');
        Queue::fake();

        $b = $this->berkasAntre(60);
        Storage::disk('local')->delete($b->local_path);

        $this->artisan('unggahan:bangkitkan')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame('gagal', $b->fresh()->status);
    }

    /** @test */
    public function perintah_bangkitkan_terjadwal_supaya_jalan_tanpa_terminal(): void
    {
        $terdaftar = collect(app(Schedule::class)->events())
            ->contains(fn ($e) => str_contains((string) $e->command, 'unggahan:bangkitkan'));

        $this->assertTrue($terdaftar,
            'Server produksi tak punya terminal; kalau tak terjadwal, perintah ini tak pernah jalan.');
    }
}
