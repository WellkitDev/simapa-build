<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\TitleProgress;
use App\Models\TitleArchive;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ArchivePdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function book(string $archiveStatus): Title
    {
        $book = Title::create(['title' => 'Buku PDF ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $owner = $this->user('production');
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 100000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $order->details->id, 'status' => 'terbit', 'assigned_role' => 'superadmin', 'started_at' => now()]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        TitleArchive::create(['title_id' => $book->id, 'status' => $archiveStatus, 'approved_at' => now()]);
        return $book->fresh();
    }

    /** @test */
    public function pdf_streams_for_approved_title(): void
    {
        $book = $this->book('disetujui');
        $this->actingAs($this->user('manager'))->get(route('archive.pdf', $book->id))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    /** @test */
    public function pdf_forbidden_when_not_approved(): void
    {
        $book = $this->book('diajukan');
        $this->actingAs($this->user('manager'))->get(route('archive.pdf', $book->id))->assertForbidden();
    }

    /** @test */
    public function pdf_forbidden_for_marketing(): void
    {
        $book = $this->book('disetujui');
        $this->actingAs($this->user('marketing'))->get(route('archive.pdf', $book->id))->assertForbidden();
    }
}
