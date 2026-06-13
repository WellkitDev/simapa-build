<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Author;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Services\TitleArchiveService;
use App\Services\GoogleDriveService;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ArchiveGroupedTitlesTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    protected function setUp(): void
    {
        parent::setUp();

        // OrderBookController constructor injects GoogleDriveService — avoid real API.
        $this->mock(GoogleDriveService::class);

        Role::create(['name' => 'marketing', 'guard_name' => 'web']);
        Role::create(['name' => 'manager',   'guard_name' => 'web']);
        Role::create(['name' => 'superadmin','guard_name' => 'web']);

        $this->marketing = User::factory()->create();
        $this->marketing->assignRole('marketing');
    }

    /**
     * Create one order-detail (title) with authors and an optional progress status.
     * Pass $status = null to simulate legacy data without a TitleProgress row.
     */
    private function makeDetail(
        string $title,
        string $type,
        ?string $status,
        array $authorNames,
        ?User $owner = null
    ): OrderDetail {
        $owner = $owner ?? $this->marketing;

        $order  = Order::factory()->create(['user_id' => $owner->id]);
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id,
            'type'     => $type,
            'title'    => $title,
        ]);

        foreach ($authorNames as $i => $name) {
            $author = Author::create([
                'name'  => $name,
                'email' => Str::slug($name) . '@example.com',
            ]);
            $detail->authors()->attach($author->id, ['position' => $i + 1]);
        }

        if ($status !== null) {
            TitleProgress::create([
                'order_detail_id' => $detail->id,
                'status'          => $status,
                'assigned_role'   => TitleProgress::getHandlerForStatus($status),
                'updated_by'      => $owner->id,
                'started_at'      => now(),
            ]);
        }

        return $detail->load(['authors', 'titleProgress', 'order.user']);
    }

    /** @test */
    public function it_groups_normalized_title_variants_into_one_summary_row(): void
    {
        $this->makeDetail('Manuscripts and Memory: Malay-Indonesian World', 'at_kolab', 'editing', ['Alice']);
        $this->makeDetail('manuscripts and memory  malay indonesian world', 'at_kolab', 'menunggu_proses', ['Bob']);

        $svc     = app(TitleArchiveService::class);
        $details = OrderDetail::with(['authors', 'titleProgress'])->get();
        $rows    = $svc->groupDetails($details);

        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame(2, $row->total_author);
        $this->assertSame('menunggu_proses', $row->bottleneck_status); // least-advanced wins
        $this->assertSame('marketing', $row->handler);                 // handler of bottleneck stage
        $this->assertTrue($row->is_mixed);
        $this->assertSame('Artikel', $row->type_label);
    }

    /** @test */
    public function it_keeps_same_title_in_different_pipelines_separate(): void
    {
        $this->makeDetail('Sejarah Nusantara', 'bk_mandiri', 'editing', ['A']);
        $this->makeDetail('Sejarah Nusantara', 'at_mandiri', 'editing', ['B']);

        $rows = app(TitleArchiveService::class)
            ->groupDetails(OrderDetail::with(['authors', 'titleProgress'])->get());

        $this->assertCount(2, $rows);
    }

    /** @test */
    public function archive_index_groups_titles_into_rows(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->makeDetail('Manuscripts and Memory: Malay-Indonesian World', 'at_kolab', 'editing', ['Alice']);
        $this->makeDetail('manuscripts and memory  malay indonesian world', 'at_kolab', 'menunggu_proses', ['Bob']);

        $resp = $this->actingAs($admin)->get(route('order.book.indexJudul'));

        $resp->assertOk();
        $rows = $resp->viewData('judulData');
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows->first()->total_author);
        $resp->assertSee('Arsip Judul');
    }

    /** @test */
    public function marketing_only_sees_own_orders_in_archive(): void
    {
        $other = User::factory()->create();
        $other->assignRole('marketing');

        $this->makeDetail('Buku Orang Lain', 'bk_mandiri', 'editing', ['X'], $other);
        $this->makeDetail('Buku Saya',       'bk_mandiri', 'editing', ['Y'], $this->marketing);

        $resp = $this->actingAs($this->marketing)->get(route('order.book.indexJudul'));

        $rows = $resp->viewData('judulData');
        $this->assertCount(1, $rows);
        $this->assertSame('Buku Saya', $rows->first()->title);
    }

    /** @test */
    public function group_detail_lists_all_orders_in_the_group(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $d1 = $this->makeDetail('Manuscripts and Memory: Malay-Indonesian World', 'at_kolab', 'editing', ['Alice']);
        $this->makeDetail('manuscripts and memory  malay indonesian world', 'at_kolab', 'menunggu_proses', ['Bob']);

        $resp = $this->actingAs($admin)->get(route('order.indexJudul.detail', $d1->id));

        $resp->assertOk();
        $details = $resp->viewData('details');
        $this->assertCount(2, $details);
        $resp->assertSee('Alice')->assertSee('Bob');
    }

    /** @test */
    public function progress_detail_autocreates_progress_for_legacy_detail(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $detail = $this->makeDetail('Buku Lama Tanpa Progress', 'bk_mandiri', null, ['Z']);
        $this->assertDatabaseMissing('tb_title_progress', ['order_detail_id' => $detail->id]);

        $resp = $this->actingAs($admin)->get(route('order.indexJudul.progress', $detail->id));

        $resp->assertOk();
        $this->assertDatabaseHas('tb_title_progress', [
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
        ]);
    }

    /** @test */
    public function sidebar_shows_renamed_archive_label_for_marketing(): void
    {
        $resp = $this->actingAs($this->marketing)->get(route('order.book.indexJudul'));

        $resp->assertOk();
        $resp->assertSee('Arsip Judul');
        $resp->assertSee('Daftar Order');
    }

    /** @test */
    public function sidebar_hides_user_management_from_marketing(): void
    {
        $resp = $this->actingAs($this->marketing)->get(route('order.book.indexJudul'));

        $resp->assertOk();
        $resp->assertDontSee('Manajemen User');
    }

    /** @test */
    public function sidebar_shows_user_management_for_superadmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $resp = $this->actingAs($admin)->get(route('order.book.indexJudul'));

        $resp->assertOk();
        $resp->assertSee('Manajemen User');
    }
}
