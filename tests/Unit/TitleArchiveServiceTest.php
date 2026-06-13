<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\TitleArchiveService;

class TitleArchiveServiceTest extends TestCase
{
    private TitleArchiveService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TitleArchiveService();
    }

    /** @test */
    public function it_normalizes_spelling_variants_to_the_same_key(): void
    {
        $a = $this->svc->normalizeTitle('Manuscripts and Memory: Malay-Indonesian World');
        $b = $this->svc->normalizeTitle('manuscripts and memory  malay indonesian world');

        $this->assertSame($a, $b);
        $this->assertSame('manuscripts and memory malay indonesian world', $a);
    }

    /** @test */
    public function it_maps_types_to_pipeline_class(): void
    {
        $this->assertSame('buku', $this->svc->pipelineClass('bk_mandiri'));
        $this->assertSame('buku', $this->svc->pipelineClass('bk_kolab'));
        $this->assertSame('artikel', $this->svc->pipelineClass('at_kolab'));
        $this->assertSame('artikel', $this->svc->pipelineClass('at_mandiri'));
    }
}
