<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use App\Services\ServiceInvoiceWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceInvoiceWorkStatusTest extends TestCase
{
    use RefreshDatabase;

    private function workflow(): ServiceInvoiceWorkflow
    {
        return app(ServiceInvoiceWorkflow::class);
    }

    /** @test */
    public function moving_to_proses_stamps_started_at_once_only(): void
    {
        $user = User::factory()->create();
        $inv  = ServiceInvoice::factory()->create(['work_status' => 'belum']);

        // Waktu WAJIB dikendalikan di sini. Kolom `work_started_at` bertipe timestamp
        // tanpa pecahan detik, dan ketiga panggilan di bawah selesai dalam milidetik —
        // tanpa travelTo(), tes ini tetap lulus walau stempelnya ditimpa setiap kali,
        // karena kedua nilai kebetulan jatuh di detik yang sama.
        $this->travelTo(Carbon::parse('2026-08-11 09:00:00'));
        $this->workflow()->changeStatus($inv, 'proses', 'mulai instalasi', $user->id);
        $inv->refresh();
        $this->assertSame('2026-08-11 09:00:00', $inv->work_started_at->toDateTimeString());

        // Bolak-balik: kembali ke Proses TIDAK boleh menimpa stempel awal.
        $this->travelTo(Carbon::parse('2026-08-12 14:30:00'));
        $this->workflow()->changeStatus($inv, 'selesai', null, $user->id);

        $this->travelTo(Carbon::parse('2026-08-13 08:15:00'));
        $this->workflow()->changeStatus($inv, 'proses', 'revisi klien', $user->id);
        $inv->refresh();

        $this->assertSame(
            '2026-08-11 09:00:00',
            $inv->work_started_at->toDateTimeString(),
            'Stempel mulai hanya dipasang sekali, tidak ditimpa saat kembali ke Proses.'
        );
    }

    /** @test */
    public function leaving_selesai_in_any_direction_clears_the_finish_date(): void
    {
        $user = User::factory()->create();
        $inv  = ServiceInvoice::factory()->create(['work_status' => 'belum']);

        $this->workflow()->changeStatus($inv, 'selesai', null, $user->id);
        $this->workflow()->changeStatus($inv, 'belum', 'salah tandai', $user->id);
        $inv->refresh();

        $this->assertSame('belum', $inv->work_status);
        $this->assertNull($inv->work_finished_at);
    }

    /** @test */
    public function a_stale_instance_cannot_strand_a_finish_date_on_an_unfinished_row(): void
    {
        $user = User::factory()->create();
        $inv  = ServiceInvoice::factory()->create(['work_status' => 'belum']);

        // Dua operator memuat invoice yang sama sebelum salah satunya menyimpan.
        // Instance kedua memegang $from yang basi ('belum'), jadi logika yang
        // berkunci pada asal akan melewatkan pembersihan tanggal selesai.
        $a = ServiceInvoice::find($inv->id);
        $b = ServiceInvoice::find($inv->id);

        $this->workflow()->changeStatus($a, 'selesai', null, $user->id);
        $this->workflow()->changeStatus($b, 'proses', null, $user->id);

        $fresh = ServiceInvoice::find($inv->id);
        $this->assertSame('proses', $fresh->work_status);
        $this->assertNull($fresh->work_finished_at, 'Baris Proses tak boleh menyimpan tanggal selesai.');
    }

    /** @test */
    public function unknown_and_terminal_statuses_are_refused(): void
    {
        $user = User::factory()->create();

        foreach (['batal', 'Selesai', 'done', ''] as $bogus) {
            $inv = ServiceInvoice::factory()->create(['work_status' => 'proses']);

            try {
                $this->workflow()->changeStatus($inv, $bogus, null, $user->id);
                $this->fail("Status '{$bogus}' seharusnya ditolak.");
            } catch (ValidationException $e) {
                // sesuai harapan
            }

            $inv->refresh();
            $this->assertSame('proses', $inv->work_status, "Status '{$bogus}' tak boleh tersimpan.");
            $this->assertCount(0, $inv->logs);
        }
    }

    /** @test */
    public function finishing_stamps_finished_at_and_leaving_clears_it(): void
    {
        $user = User::factory()->create();
        $inv  = ServiceInvoice::factory()->create(['work_status' => 'proses']);

        $this->workflow()->changeStatus($inv, 'selesai', null, $user->id);
        $inv->refresh();
        $this->assertNotNull($inv->work_finished_at);

        // Tanggal selesai tidak boleh berbohong setelah pekerjaan dibuka lagi.
        $this->workflow()->changeStatus($inv, 'proses', 'klien minta revisi tema', $user->id);
        $inv->refresh();
        $this->assertNull($inv->work_finished_at);
    }

    /** @test */
    public function every_transition_writes_one_log_row(): void
    {
        $user = User::factory()->create();
        $inv  = ServiceInvoice::factory()->create(['work_status' => 'belum']);

        $this->workflow()->changeStatus($inv, 'proses', 'mulai', $user->id);
        $this->workflow()->changeStatus($inv, 'selesai', 'beres', $user->id);
        $inv->refresh();

        $this->assertCount(2, $inv->logs);

        $latest = $inv->logs->first();
        $this->assertSame('status_changed', $latest->event);
        $this->assertSame('proses',  $latest->from_status);
        $this->assertSame('selesai', $latest->to_status);
        $this->assertSame('beres',   $latest->note);
        $this->assertSame($user->id, $latest->changed_by);
    }

    /** @test */
    public function transition_to_the_same_status_is_a_no_op(): void
    {
        $user = User::factory()->create();
        $inv  = ServiceInvoice::factory()->create(['work_status' => 'proses']);

        $moved = $this->workflow()->changeStatus($inv, 'proses', 'tidak berubah', $user->id);
        $inv->refresh();

        $this->assertFalse($moved);
        $this->assertCount(0, $inv->logs);
    }

    /** @test */
    public function a_failed_log_write_rolls_the_status_back(): void
    {
        $inv = ServiceInvoice::factory()->create(['work_status' => 'belum']);

        // `changed_by` punya foreign key ke users, jadi id yang tidak ada membuat
        // INSERT log gagal SETELAH baris invoice diperbarui. Keduanya harus jatuh
        // bersama — tak boleh ada perpindahan status yang tidak punya jejak.
        try {
            $this->workflow()->changeStatus($inv, 'proses', null, 999999);
            $this->fail('INSERT log dengan changed_by tak dikenal seharusnya gagal.');
        } catch (\Illuminate\Database\QueryException $e) {
            // yang diuji adalah keadaan sesudahnya, bukan pesannya
        }

        // Dibaca ulang dari basis data: instance di memori sudah terlanjur dimutasi
        // oleh update() walau transaksinya dibatalkan.
        $this->assertSame('belum', ServiceInvoice::find($inv->id)->work_status);
        $this->assertSame(0, $inv->logs()->count());
    }
}
