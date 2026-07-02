<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TitleManuscriptStatusTest extends TestCase
{
    use RefreshDatabase;

    private function linkedTitle(string $jenis, array $statuses): Title
    {
        $user = User::factory()->create();
        $type = $jenis === 'buku' ? 'bk_mandiri' : 'at_mandiri';
        $title = Title::create(['title' => 'Judul Uji', 'jenis' => $jenis, 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);

        foreach ($statuses as $i => $st) {
            $order = Order::create(['code_order' => 'ORD-T' . $i . '-' . uniqid(), 'user_id' => $user->id, 'status' => 'pending', 'ordered_at' => now()]);
            $detail = OrderDetail::create([
                'order_id' => $order->id, 'title_id' => $title->id, 'type' => $type,
                'title' => 'Judul Uji', 'slug' => 'judul-uji-' . $i, 'cost_amount' => 0,
                'naskah_type' => 'mandiri', 'publication_type' => 'mandiri',
            ]);
            TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $st, 'assigned_role' => 'production', 'started_at' => now()]);
        }

        return $title->load('orderDetails.titleProgress');
    }

    /** @test */
    public function manuscript_status_returns_earliest_stage_bottleneck(): void
    {
        // BOOK_STAGES: menunggu_proses, editing, layout, ... → editing lebih awal dari layout
        $title = $this->linkedTitle('buku', ['layout', 'editing']);
        $this->assertSame('editing', $title->manuscriptStatus());
    }

    /** @test */
    public function manuscript_status_null_without_orders(): void
    {
        $title = Title::create(['title' => 'Tanpa Order', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->assertNull($title->load('orderDetails.titleProgress')->manuscriptStatus());
    }

    /** @test */
    public function stage_label_formats_special_cases(): void
    {
        $this->assertSame('LoA', Title::stageLabel('loa'));
        $this->assertSame('ISBN', Title::stageLabel('isbn'));
        $this->assertSame('Menunggu Proses', Title::stageLabel('menunggu_proses'));
        $this->assertNull(Title::stageLabel(null));
    }

    /** @test */
    public function manuscript_status_label_uses_status(): void
    {
        $title = $this->linkedTitle('artikel', ['loa']);
        $this->assertSame('LoA', $title->manuscriptStatusLabel());
    }
}
