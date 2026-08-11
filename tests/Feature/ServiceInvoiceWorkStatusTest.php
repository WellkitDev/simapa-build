<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use App\Services\ServiceInvoiceWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->workflow()->changeStatus($inv, 'proses', 'mulai instalasi', $user->id);
        $inv->refresh();
        $firstStamp = $inv->work_started_at;
        $this->assertNotNull($firstStamp);

        // Bolak-balik: kembali ke Proses TIDAK boleh menimpa stempel awal.
        $this->workflow()->changeStatus($inv, 'selesai', null, $user->id);
        $this->workflow()->changeStatus($inv, 'proses', 'revisi klien', $user->id);
        $inv->refresh();

        $this->assertEquals($firstStamp->toDateTimeString(), $inv->work_started_at->toDateTimeString());
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
