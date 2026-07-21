<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyKeyModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function key_column_is_unique(): void
    {
        IdempotencyKey::create(['key' => 'dup-token', 'method' => 'POST', 'path' => 'x']);

        $this->expectException(QueryException::class);
        IdempotencyKey::create(['key' => 'dup-token', 'method' => 'POST', 'path' => 'y']);
    }

    /** @test */
    public function created_at_is_set_automatically(): void
    {
        $row = IdempotencyKey::create(['key' => 'tok-1', 'method' => 'POST', 'path' => 'x']);

        $this->assertNotNull($row->fresh()->created_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $row->fresh()->created_at);
    }

    /** @test */
    public function prune_command_deletes_keys_older_than_24_hours(): void
    {
        $old = IdempotencyKey::create(['key' => 'old', 'method' => 'POST', 'path' => 'x']);
        $old->forceFill(['created_at' => now()->subHours(25)])->save();

        IdempotencyKey::create(['key' => 'fresh', 'method' => 'POST', 'path' => 'x']); // created_at = now

        $this->artisan('idempotency:prune')->assertExitCode(0);

        $this->assertNull(IdempotencyKey::where('key', 'old')->first());
        $this->assertNotNull(IdempotencyKey::where('key', 'fresh')->first());
    }
}
