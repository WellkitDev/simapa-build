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
use App\Models\TitleArchiveArtifact;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleArchiveTest extends TestCase
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

    private function eligibleBook(): Title
    {
        $book = Title::create(['title' => 'Buku Arsip ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $owner = $this->user('production');
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 100000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $order->details->id, 'status' => 'terbit', 'assigned_role' => 'superadmin', 'started_at' => now()]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        return $book->fresh();
    }

    /** @test */
    public function admin_saves_artifacts_with_pic(): void
    {
        $book = $this->eligibleBook();
        $this->mock(GoogleDriveService::class, fn ($m) => $m->shouldReceive('uploadFile')->andReturn(['url' => 'http://drive/f.pdf', 'id' => 'x', 'name' => 'f']));
        $pic = $this->user('production');

        $this->actingAs($this->user('admin'))->put(route('archive.artifacts', $book->id), [
            'fixed' => [
                'isbn'         => ['value' => '978-1', 'pic_user_id' => $pic->id],
                'barcode_file' => ['file' => UploadedFile::fake()->create('bc.pdf', 5)],
            ],
            'custom' => [['label' => 'Sertifikat', 'type' => 'link', 'value' => 'http://x']],
        ])->assertRedirect(route('archive.show', $book->id));

        $this->assertSame('978-1', TitleArchiveArtifact::where('title_id', $book->id)->where('key', 'isbn')->first()->value);
        $this->assertSame(1, TitleArchiveArtifact::where('title_id', $book->id)->where('is_custom', true)->count());
    }

    /** @test */
    public function submit_rejected_when_not_eligible(): void
    {
        $book = Title::create(['title' => 'Belum', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->actingAs($this->user('admin'))->post(route('archive.submit', $book->id))->assertRedirect();
        $this->assertNull(TitleArchive::where('title_id', $book->id)->first());
    }

    /** @test */
    public function submit_sets_diajukan_then_superadmin_approves(): void
    {
        $book = $this->eligibleBook();
        $this->actingAs($this->user('admin'))->post(route('archive.submit', $book->id))->assertRedirect();
        $this->assertSame('diajukan', TitleArchive::where('title_id', $book->id)->first()->status);

        $this->actingAs($this->user('superadmin'))->post(route('archive.approve', $book->id), ['approval_note' => 'ok'])->assertRedirect();
        $this->assertSame('disetujui', TitleArchive::where('title_id', $book->id)->first()->status);
    }

    /** @test */
    public function admin_cannot_approve(): void
    {
        $book = $this->eligibleBook();
        $this->actingAs($this->user('admin'))->post(route('archive.approve', $book->id), ['approval_note' => 'x'])->assertRedirect()->assertSessionHas('error');
    }

    /** @test */
    public function index_lists_approved(): void
    {
        $book = $this->eligibleBook();
        TitleArchive::create(['title_id' => $book->id, 'status' => 'disetujui', 'approved_at' => now()]);
        $this->actingAs($this->user('manager'))->get(route('archive.index'))
            ->assertOk()->assertSee($book->title);
    }
}
