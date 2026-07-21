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
}
