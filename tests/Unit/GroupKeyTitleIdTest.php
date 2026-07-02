<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Title;
use App\Models\OrderDetail;
use App\Services\TitleArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GroupKeyTitleIdTest extends TestCase
{
    use RefreshDatabase;

    private function title(string $jenis = 'buku'): Title
    {
        return Title::create(['title' => 'Judul X', 'jenis' => $jenis, 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    /** @test */
    public function detail_with_title_id_uses_title_prefixed_group_key(): void
    {
        $title = $this->title();
        $detail = OrderDetail::factory()->create(['title_id' => $title->id, 'type' => 'bk_mandiri', 'title' => 'Apa Saja']);

        $this->assertSame('title:' . $title->id, $detail->fresh()->group_key);
    }

    /** @test */
    public function same_title_id_different_title_string_share_group_key(): void
    {
        $title = $this->title();
        $a = OrderDetail::factory()->create(['title_id' => $title->id, 'type' => 'bk_mandiri', 'title' => 'Judul A']);
        $b = OrderDetail::factory()->create(['title_id' => $title->id, 'type' => 'bk_kolab', 'title' => 'Judul B Berbeda']);

        $this->assertSame($a->fresh()->group_key, $b->fresh()->group_key);
    }

    /** @test */
    public function detail_without_title_id_falls_back_to_derived(): void
    {
        $d = OrderDetail::factory()->create(['title_id' => null, 'type' => 'bk_mandiri', 'title' => 'Hello World']);
        $this->assertSame('buku|hello world', $d->fresh()->group_key);
    }

    /** @test */
    public function archive_group_key_uses_title_id_when_present(): void
    {
        $title = $this->title();
        $d = OrderDetail::factory()->create(['title_id' => $title->id, 'type' => 'bk_mandiri', 'title' => 'Z']);
        $this->assertSame('title:' . $title->id, (new TitleArchiveService())->groupKey($d));
    }
}
