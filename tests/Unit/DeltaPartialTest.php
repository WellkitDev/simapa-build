<?php
// tests/Unit/DeltaPartialTest.php
// Render tests untuk resources/views/dashboard/partials/delta.blade.php.
// Ini melengkapi MarketingDashboardServiceTest: yang di sana menguji bentuk array
// delta(), yang di sini menguji bagaimana array itu dirender jadi HTML — termasuk
// pembalikan warna invertGood (piutang naik = buruk) dan tampilan cap >999%.

namespace Tests\Unit;

use Tests\TestCase;

class DeltaPartialTest extends TestCase
{
    private function render(array $delta, ?bool $invertGood = null): string
    {
        $data = ['delta' => $delta];
        if ($invertGood !== null) {
            $data['invertGood'] = $invertGood;
        }

        return (string) $this->view('dashboard.partials.delta', $data);
    }

    /** @test */
    public function invert_good_up_lonjakan_piutang_dirender_merah(): void
    {
        $html = $this->render(['pct' => 15.0, 'dir' => 'up', 'capped' => false], invertGood: true);

        $this->assertStringContainsString('class="text-danger mb-0 mt-2"', $html);
        $this->assertStringNotContainsString('text-success', $html);
    }

    /** @test */
    public function invert_good_down_penurunan_piutang_dirender_hijau(): void
    {
        $html = $this->render(['pct' => 15.0, 'dir' => 'down', 'capped' => false], invertGood: true);

        $this->assertStringContainsString('class="text-success mb-0 mt-2"', $html);
        $this->assertStringNotContainsString('text-danger', $html);
    }

    /** @test */
    public function default_tanpa_invert_good_naik_tetap_hijau(): void
    {
        $html = $this->render(['pct' => 15.0, 'dir' => 'up', 'capped' => false]);

        $this->assertStringContainsString('class="text-success mb-0 mt-2"', $html);
        $this->assertStringNotContainsString('text-danger', $html);
    }

    /** @test */
    public function flat_selalu_abu_abu_terlepas_dari_invert_good(): void
    {
        $htmlNormal = $this->render(['pct' => 0.0, 'dir' => 'flat', 'capped' => false], invertGood: false);
        $htmlInvert = $this->render(['pct' => 0.0, 'dir' => 'flat', 'capped' => false], invertGood: true);

        $this->assertStringContainsString('class="text-muted mb-0 mt-2"', $htmlNormal);
        $this->assertStringContainsString('class="text-muted mb-0 mt-2"', $htmlInvert);
    }

    /** @test */
    public function capped_menampilkan_lebih_dari_999_persen_dengan_title_persentase_asli(): void
    {
        $html = $this->render(['pct' => 9900.0, 'dir' => 'up', 'capped' => true]);

        // {{ }} meng-escape HTML, jadi '>' jadi '&gt;' di output — cek teks apa adanya.
        $this->assertStringContainsString('&gt;999% vs periode lalu', $html);
        $this->assertStringContainsString('title="9900% vs periode lalu"', $html);
    }

    /** @test */
    public function pct_null_dengan_arah_naik_dirender_baru(): void
    {
        $html = $this->render(['pct' => null, 'dir' => 'up', 'capped' => false]);

        $this->assertStringContainsString('baru', $html);
        $this->assertStringNotContainsString('vs periode lalu', $html);
    }
}
