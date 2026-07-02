<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Title;
use App\Models\OrderDetail;
use App\Services\TitleBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TitleBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function backfill_links_old_orders_of_same_title_to_one_title(): void
    {
        $d1 = OrderDetail::factory()->create(['title_id' => null, 'type' => 'bk_mandiri', 'title' => 'Buku Lama']);
        $d2 = OrderDetail::factory()->create(['title_id' => null, 'type' => 'bk_kolab', 'title' => 'buku lama']); // ternormalisasi sama, se-pipeline

        $n = (new TitleBackfillService())->run();

        $this->assertSame(2, $n);
        $this->assertNotNull($d1->fresh()->title_id);
        $this->assertSame($d1->fresh()->title_id, $d2->fresh()->title_id);

        $title = Title::find($d1->fresh()->title_id);
        $this->assertSame('order', $title->asal);
        $this->assertSame('disetujui', $title->status);
        $this->assertSame('buku', $title->jenis);
        $this->assertNotNull($title->code);
        $this->assertSame('title:' . $title->id, $d1->fresh()->group_key);
    }

    /** @test */
    public function backfill_separates_different_pipelines(): void
    {
        $buku = OrderDetail::factory()->create(['title_id' => null, 'type' => 'bk_mandiri', 'title' => 'Sama Judul']);
        $art  = OrderDetail::factory()->create(['title_id' => null, 'type' => 'at_mandiri', 'title' => 'Sama Judul']);

        (new TitleBackfillService())->run();

        $this->assertNotSame($buku->fresh()->title_id, $art->fresh()->title_id);
        $this->assertSame('artikel', Title::find($art->fresh()->title_id)->jenis);
    }

    /** @test */
    public function backfill_reuses_existing_matching_title(): void
    {
        $existing = Title::create(['title' => 'Judul Ada', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $d = OrderDetail::factory()->create(['title_id' => null, 'type' => 'bk_mandiri', 'title' => 'Judul Ada']);

        (new TitleBackfillService())->run();

        $this->assertSame($existing->id, $d->fresh()->title_id);
        $this->assertSame(1, Title::where('title', 'Judul Ada')->count());
    }

    /** @test */
    public function backfill_ignores_details_that_already_have_title_id(): void
    {
        $title = Title::create(['title' => 'Sudah', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        OrderDetail::factory()->create(['title_id' => $title->id, 'type' => 'bk_mandiri', 'title' => 'Sudah']);

        $this->assertSame(0, (new TitleBackfillService())->run());
    }
}
