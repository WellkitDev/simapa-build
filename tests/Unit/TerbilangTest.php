<?php

namespace Tests\Unit;

use App\Support\Terbilang;
use PHPUnit\Framework\TestCase;

class TerbilangTest extends TestCase
{
    /** @test */
    public function converts_rupiah_to_indonesian_words(): void
    {
        $this->assertSame('Nol rupiah', Terbilang::rupiah(0));
        $this->assertSame('Satu rupiah', Terbilang::rupiah(1));
        $this->assertSame('Sebelas rupiah', Terbilang::rupiah(11));
        $this->assertSame('Dua puluh satu rupiah', Terbilang::rupiah(21));
        $this->assertSame('Seratus rupiah', Terbilang::rupiah(100));
        $this->assertSame('Seribu rupiah', Terbilang::rupiah(1000));
        $this->assertSame('Dua ribu rupiah', Terbilang::rupiah(2000));
        $this->assertSame('Satu juta dua ratus lima puluh ribu rupiah', Terbilang::rupiah(1250000));
        $this->assertSame('Lima juta tujuh ratus ribu rupiah', Terbilang::rupiah(5700000));
    }
}
