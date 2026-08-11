<?php

namespace Tests\Feature;

use App\Models\ServiceCatalog;
use App\Models\ServiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCatalogModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function catalog_exposes_category_labels(): void
    {
        $this->assertArrayHasKey('instalasi', ServiceCatalog::CATEGORIES);
        $this->assertArrayHasKey('similarity', ServiceCatalog::CATEGORIES);
        $this->assertSame('Layanan Perbaikan', ServiceCatalog::CATEGORIES['perbaikan']);
    }

    /** @test */
    public function price_label_shows_range_when_price_max_present(): void
    {
        $fixed = ServiceCatalog::factory()->create(['price' => 750000, 'price_max' => null]);
        $range = ServiceCatalog::factory()->create(['price' => 500000, 'price_max' => 1000000]);

        $this->assertSame('Rp 750.000', $fixed->priceLabel());
        $this->assertSame('Rp 500.000 – Rp 1.000.000', $range->priceLabel());
    }

    /** @test */
    public function category_label_maps_known_key_and_falls_back_for_unknown_key(): void
    {
        $this->assertSame('Layanan Perbaikan', ServiceCatalog::categoryLabel('perbaikan'));
        $this->assertSame('tidak-dikenal', ServiceCatalog::categoryLabel('tidak-dikenal'));
    }

    /** @test */
    public function client_is_soft_deleted(): void
    {
        $client = ServiceClient::factory()->create();
        $client->delete();

        $this->assertSoftDeleted('tb_service_clients', ['id' => $client->id]);
        $this->assertCount(1, ServiceClient::withTrashed()->get());
    }
}
