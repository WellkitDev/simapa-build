<?php
// tests/Unit/OrderDetailGroupKeyTest.php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\OrderDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderDetailGroupKeyTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function group_key_is_set_on_save(): void
    {
        $detail = OrderDetail::factory()->create(['type' => 'bk_mandiri', 'title' => 'Hello, World!']);

        $this->assertNotNull($detail->group_key);
        $this->assertEquals('buku|hello world', $detail->group_key);
    }

    /** @test */
    public function same_normalized_title_and_pipeline_share_group_key(): void
    {
        $a = OrderDetail::factory()->create(['type' => 'bk_mandiri', 'title' => 'Hello, World!']);
        $b = OrderDetail::factory()->create(['type' => 'bk_kolab',   'title' => 'hello world']);

        $this->assertEquals($a->group_key, $b->group_key);
    }

    /** @test */
    public function different_pipeline_gets_different_group_key(): void
    {
        $buku    = OrderDetail::factory()->create(['type' => 'bk_mandiri', 'title' => 'Sama Judul']);
        $artikel = OrderDetail::factory()->create(['type' => 'at_mandiri', 'title' => 'Sama Judul']);

        $this->assertNotEquals($buku->group_key, $artikel->group_key);
    }

    /** @test */
    public function group_key_updates_when_title_changes(): void
    {
        $detail = OrderDetail::factory()->create(['type' => 'bk_mandiri', 'title' => 'Judul Awal']);
        $detail->update(['title' => 'Judul Baru']);

        $this->assertEquals('buku|judul baru', $detail->fresh()->group_key);
    }
}
