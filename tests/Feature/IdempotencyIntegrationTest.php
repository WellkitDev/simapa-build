<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class IdempotencyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function idempotent_directive_renders_hidden_token_field(): void
    {
        $html = Blade::render('<form>@idempotent</form>');

        $this->assertStringContainsString('name="_idempotency_key"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        // Ada value UUID yang terisi (bukan kosong).
        $this->assertMatchesRegularExpression(
            '/name="_idempotency_key"\s+value="[0-9a-f-]{36}"/',
            $html
        );
    }
}
