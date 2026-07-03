<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TitleIsbnEligibleTest extends TestCase
{
    use RefreshDatabase;

    private function bookAtStage(?string $stage): Title
    {
        $book = Title::create(['title' => 'Buku ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        if ($stage !== null) {
            $owner = User::factory()->create();
            $order = Order::create(['code_order' => 'ORD-EL-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
            $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
            TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $stage, 'assigned_role' => 'production', 'started_at' => now()]);
        }
        return $book->fresh();
    }

    /** @test */
    public function eligible_when_manuscript_reached_isbn_or_beyond(): void
    {
        foreach (['isbn', 'cetak', 'terbit'] as $stage) {
            $this->assertTrue($this->bookAtStage($stage)->isbnEligible(), "gagal utk tahap {$stage}");
        }
    }

    /** @test */
    public function ineligible_before_isbn_or_without_orders_or_article(): void
    {
        foreach (['editing', 'layout', 'proofreading'] as $stage) {
            $this->assertFalse($this->bookAtStage($stage)->isbnEligible(), "seharusnya belum layak: {$stage}");
        }
        $this->assertFalse($this->bookAtStage(null)->isbnEligible()); // tanpa order
        $art = Title::create(['title' => 'Artikel', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->assertFalse($art->isbnEligible());
    }
}
