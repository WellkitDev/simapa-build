<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Title;
use App\Services\TitleCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TitleCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    private TitleCodeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TitleCodeService();
    }

    /** @test */
    public function it_builds_code_from_first_four_words(): void
    {
        $code = $this->svc->generate('Blockchain dalam Fintech Syariah: Transparansi Akad untuk UMKM Halal');
        $this->assertSame('BDFS', $code);
    }

    /** @test */
    public function single_word_title_falls_back_to_letters(): void
    {
        $this->assertSame('FINT', $this->svc->generate('Fintech'));
    }

    /** @test */
    public function symbol_only_title_uses_fallback(): void
    {
        $this->assertSame('JDL', $this->svc->generate('—  :  —'));
    }

    /** @test */
    public function collision_appends_number(): void
    {
        Title::create(['title' => 'X', 'code' => 'BDFS', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->assertSame('BDFS2', $this->svc->generate('Blockchain dalam Fintech Syariah'));
    }

    /** @test */
    public function ignore_id_excludes_self_when_regenerating(): void
    {
        $t = Title::create(['title' => 'Blockchain dalam Fintech Syariah', 'code' => 'BDFS', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->assertSame('BDFS', $this->svc->generate('Blockchain dalam Fintech Syariah', $t->id));
    }
}
