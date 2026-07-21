<?php

namespace Tests\Feature;

use App\Models\CashCategory;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnforceIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Route uji berada di grup `web` agar middleware EnforceIdempotency ikut jalan.
        // Sukses -> buat 1 CashCategory lalu redirect. Gagal -> redirect back dgn error.
        Route::middleware('web')->group(function () {
            Route::post('/__idem_ok', function () {
                CashCategory::create(['name' => 'X', 'jenis' => 'pemasukan']);
                return redirect('/origin')->with('success', 'ok');
            });
            Route::post('/__idem_fail', function () {
                return redirect('/origin')->withErrors(['x' => 'bad']);
            });
        });
    }

    /** @test */
    public function duplicate_token_is_short_circuited_and_writes_once(): void
    {
        $user = User::factory()->create();
        $token = 'tok-abc';

        $first = $this->actingAs($user)->from('/origin')
            ->post('/__idem_ok', ['_idempotency_key' => $token]);
        $first->assertRedirect('/origin');

        $second = $this->actingAs($user)->from('/origin')
            ->post('/__idem_ok', ['_idempotency_key' => $token]);
        $second->assertRedirect('/origin');
        $second->assertSessionHas('info');

        $this->assertSame(1, CashCategory::where('name', 'X')->count());
        $this->assertSame(1, IdempotencyKey::where('key', $token)->count());
    }

    /** @test */
    public function requests_without_token_are_not_deduped_fail_open(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/origin')->post('/__idem_ok');
        $this->actingAs($user)->from('/origin')->post('/__idem_ok');

        $this->assertSame(2, CashCategory::where('name', 'X')->count());
        $this->assertSame(0, IdempotencyKey::count());
    }

    /** @test */
    public function failed_first_request_releases_claim_so_retry_works(): void
    {
        $user = User::factory()->create();
        $token = 'tok-retry';

        // Request pertama gagal (validasi) -> klaim dilepas.
        $this->actingAs($user)->from('/origin')
            ->post('/__idem_fail', ['_idempotency_key' => $token]);
        $this->assertSame(0, IdempotencyKey::where('key', $token)->count());

        // Retry token sama ke endpoint sukses -> tetap tereksekusi.
        $this->actingAs($user)->from('/origin')
            ->post('/__idem_ok', ['_idempotency_key' => $token]);
        $this->assertSame(1, CashCategory::where('name', 'X')->count());
    }
}
