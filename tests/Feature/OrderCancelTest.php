<?php

namespace Tests\Feature;

use App\Exceptions\OrderCancellationException;
use App\Models\CashEntry;
use App\Models\CashPeriodLock;
use App\Models\Invoice;
use App\Models\InvoiceLog;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\Tagihan;
use App\Models\TitleProgress;
use App\Models\User;
use App\Notifications\DatabaseNotification;
use App\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function kolom_pembatalan_dan_soft_delete_tersedia(): void
    {
        $this->assertTrue(Schema::hasColumns('tb_orders', ['cancel_reason', 'cancelled_by', 'cancelled_at', 'deleted_at']));
        $this->assertTrue(Schema::hasColumn('tb_order_details', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('tb_title_progress', 'deleted_at'));
    }

    /** @test */
    public function tiga_model_memakai_soft_deletes(): void
    {
        $owner  = $this->user('marketing');
        $order  = Order::factory()->create(['user_id' => $owner->id]);
        $detail = OrderDetail::factory()->create(['order_id' => $order->id]);
        $prog   = TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
            'assigned_role'   => 'marketing',
            'updated_by'      => $owner->id,
            'started_at'      => now(),
        ]);

        $order->delete();
        $detail->delete();
        $prog->delete();

        $this->assertSoftDeleted('tb_orders', ['id' => $order->id]);
        $this->assertSoftDeleted('tb_order_details', ['id' => $detail->id]);
        $this->assertSoftDeleted('tb_title_progress', ['id' => $prog->id]);
        $this->assertNull(Order::find($order->id));
        $this->assertNull(OrderDetail::find($detail->id));
        $this->assertNull(TitleProgress::find($prog->id));
    }

    /** @test */
    public function field_pembatalan_bisa_diisi_dan_cancelled_at_ter_cast(): void
    {
        $owner = $this->user('marketing');
        $order = Order::factory()->create([
            'user_id'       => $owner->id,
            'cancel_reason' => 'Salah input harga',
            'cancelled_by'  => $owner->id,
            'cancelled_at'  => '2026-08-03 10:00:00',
        ]);

        $fresh = $order->fresh();
        $this->assertSame('Salah input harga', $fresh->cancel_reason);
        $this->assertSame($owner->id, $fresh->cancelled_by);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->cancelled_at);
    }

    /** Order + detail + progress lengkap, milik $owner. */
    private function makeOrder(User $owner, ?string $code = null): Order
    {
        $code ??= 'ORD-' . strtoupper(\Illuminate\Support\Str::random(8));

        $order = Order::create([
            'code_order' => $code,
            'user_id'    => $owner->id,
            'status'     => 'pending',
            'ordered_at' => '2026-08-01',
        ]);

        $detail = OrderDetail::create([
            'order_id'         => $order->id,
            'type'             => 'bk_mandiri',
            'title'            => 'Judul Uji',
            'slug'             => 'judul-uji-' . $order->id,
            'naskah_type'      => 'mandiri',
            'publication_type' => 'regular',
            'cost_amount'      => 1000000,
        ]);

        TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
            'assigned_role'   => 'marketing',
            'updated_by'      => $owner->id,
            'started_at'      => now(),
        ]);

        return $order->fresh();
    }

    /** Payment `paid` + approval berstatus $approvalStatus (seperti alur submit sungguhan). */
    private function addPayment(Order $order, string $approvalStatus = 'pending', int $amount = 500000): Payment
    {
        $payment = Payment::create([
            'order_id'     => $order->id,
            'payment_type' => 'dp',
            'amount'       => $amount,
            'status'       => 'paid',
            'paid_at'      => '2026-08-02',
        ]);

        PaymentApproval::create(['payment_id' => $payment->id, 'status' => $approvalStatus]);

        return $payment;
    }

    /** @test */
    public function gerbang_batal_membaca_approval_bukan_status_payment(): void
    {
        $owner = $this->user('marketing');

        $polos = $this->makeOrder($owner, 'ORD-202608-0001');
        $this->assertTrue($polos->isCancellable());
        $this->assertTrue($polos->isEditable());
        $this->assertFalse($polos->hasApprovedPayment());

        // Bukti bayar sudah diunggah (payment 'paid') tapi approval MASIH pending →
        // tetap boleh dibatalkan. Inilah kasus yang paling butuh dibatalkan.
        $menunggu = $this->makeOrder($owner, 'ORD-202608-0002');
        $this->addPayment($menunggu, 'pending');
        $this->assertFalse($menunggu->fresh()->hasApprovedPayment());
        $this->assertTrue($menunggu->fresh()->isCancellable());

        // Approval sudah 'approved' → Batal tertutup, Edit TETAP terbuka.
        $disetujui = $this->makeOrder($owner, 'ORD-202608-0003');
        $this->addPayment($disetujui, 'approved');
        $this->assertTrue($disetujui->fresh()->hasApprovedPayment());
        $this->assertFalse($disetujui->fresh()->isCancellable());
        $this->assertTrue($disetujui->fresh()->isEditable());
    }

    /** @test */
    public function order_dibatalkan_tidak_editable_dan_tidak_cancellable(): void
    {
        $order = $this->makeOrder($this->user('marketing'));
        $order->update(['status' => 'dibatalkan']);
        $order->delete();

        $trashed = Order::withTrashed()->find($order->id);
        $this->assertTrue($trashed->isCancelled());
        $this->assertFalse($trashed->isEditable());
        $this->assertFalse($trashed->isCancellable());
    }

    /** @test */
    public function gerbang_konsisten_baik_relasi_dimuat_maupun_tidak(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        $this->addPayment($order, 'approved');

        $lazy   = Order::find($order->id);
        $eager  = Order::with('payments.approval')->find($order->id);

        $this->assertTrue($lazy->hasApprovedPayment());
        $this->assertTrue($eager->hasApprovedPayment());
        $this->assertTrue($eager->relationLoaded('payments'));
        $this->assertFalse($eager->isCancellable());
    }

    /** @test */
    public function batal_menghapus_order_detail_dan_progress_secara_berjenjang(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        $detailId = $order->details->id;
        $progId   = TitleProgress::where('order_detail_id', $detailId)->value('id');

        app(OrderCancellationService::class)->cancel($order, 'Salah input harga', $owner);

        $this->assertSoftDeleted('tb_orders', ['id' => $order->id]);
        $this->assertSoftDeleted('tb_order_details', ['id' => $detailId]);
        $this->assertSoftDeleted('tb_title_progress', ['id' => $progId]);

        $trashed = Order::withTrashed()->find($order->id);
        $this->assertSame('dibatalkan', $trashed->status);
        $this->assertSame('Salah input harga', $trashed->cancel_reason);
        $this->assertSame($owner->id, $trashed->cancelled_by);
        $this->assertNotNull($trashed->cancelled_at);
    }

    /** @test */
    public function alasan_boleh_kosong(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);

        app(OrderCancellationService::class)->cancel($order, null, $owner);

        $this->assertNull(Order::withTrashed()->find($order->id)->cancel_reason);
        $this->assertSoftDeleted('tb_orders', ['id' => $order->id]);
    }

    /** @test */
    public function batal_ditolak_bila_payment_sudah_disetujui(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        $this->addPayment($order, 'approved');

        $this->expectException(OrderCancellationException::class);
        app(OrderCancellationService::class)->cancel($order->fresh(), null, $owner);
    }

    /** @test */
    public function order_dibatalkan_hilang_dari_papan_manuskrip(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);

        $this->assertSame(1, TitleProgress::count());

        // Manager (bukan production-only) supaya scope papan default 'all', bukan
        // 'mine' — progress baru berstatus 'menunggu_proses' (handler marketing) jadi
        // tak lolos filter scope=mine milik viewer production. Ini menembak rute
        // manuscript.board sungguhan (ManuscriptTrackerController@index), bukan cuma
        // menghitung baris — supaya klaim arsitektur "hilang dari papan lewat global
        // scope, tanpa menyentuh controller-nya" benar-benar teruji.
        $viewer = $this->user('manager');
        $this->actingAs($viewer)
            ->get(route('manuscript.board'))
            ->assertOk()
            ->assertSee('Judul Uji');

        app(OrderCancellationService::class)->cancel($order, null, $owner);

        $this->assertSame(0, TitleProgress::count());
        $this->assertSame(0, OrderDetail::count());
        $this->assertSame(1, TitleProgress::withTrashed()->count());

        $this->actingAs($viewer)
            ->get(route('manuscript.board'))
            ->assertOk()
            ->assertDontSee('Judul Uji');
    }

    /** @test */
    public function order_tanpa_detail_tetap_bisa_dibatalkan(): void
    {
        $owner = $this->user('marketing');
        $order = Order::create([
            'code_order' => 'ORD-202608-9999',
            'user_id'    => $owner->id,
            'status'     => 'pending',
            'ordered_at' => '2026-08-01',
        ]);

        app(OrderCancellationService::class)->cancel($order, null, $owner);

        $this->assertSoftDeleted('tb_orders', ['id' => $order->id]);
        $this->assertSame('dibatalkan', Order::withTrashed()->find($order->id)->status);
    }

    /** Invoice + log seperti yang dibuat PaymentBookController::store(). */
    private function addInvoice(Order $order, Payment $payment): Invoice
    {
        $invoice = Invoice::create([
            'order_id'   => $order->id,
            'payment_id' => $payment->id,
            'invoice_no' => 'INV-' . $order->id . '-' . $payment->id,
            'issued_at'  => '2026-08-02',
            'due_at'     => '2026-08-09',
            'status'     => 'diterbitkan',
        ]);

        InvoiceLog::create([
            'invoice_id'  => $invoice->id,
            'from_status' => '',
            'to_status'   => 'diterbitkan',
            'changed_by'  => $order->user_id,
            'note'        => 'Invoice dibuat otomatis dari pembayaran.',
        ]);

        return $invoice;
    }

    /** @test */
    public function batal_membatalkan_payment_approval_invoice_dan_menghapus_entri_kas(): void
    {
        $owner   = $this->user('marketing');
        $order   = $this->makeOrder($owner);
        $payment = $this->addPayment($order, 'pending');
        $invoice = $this->addInvoice($order, $payment);

        // PaymentObserver sudah membuat entri kas saat payment ber-status 'paid'.
        $this->assertDatabaseHas('tb_cash_entries', ['payment_id' => $payment->id]);

        app(OrderCancellationService::class)->cancel($order->fresh(), 'Dobel input', $owner);

        $this->assertSame('batal', $payment->fresh()->status);
        $this->assertSame('rejected', $payment->fresh()->approval->status);
        $this->assertSame('dibatalkan', $invoice->fresh()->status);
        $this->assertSame($owner->id, $invoice->fresh()->cancelled_by);
        $this->assertNotNull($invoice->fresh()->cancelled_at);

        $this->assertDatabaseMissing('tb_cash_entries', ['payment_id' => $payment->id]);
        $this->assertDatabaseHas('tb_invoice_logs', [
            'invoice_id'  => $invoice->id,
            'from_status' => 'diterbitkan',
            'to_status'   => 'dibatalkan',
        ]);
    }

    /** @test */
    public function payment_yang_dibatalkan_keluar_dari_perhitungan_uang_masuk(): void
    {
        $owner   = $this->user('marketing');
        $order   = $this->makeOrder($owner);
        $payment = $this->addPayment($order, 'pending', 750000);

        $this->assertSame(750000, $order->fresh()->paidNet());

        app(OrderCancellationService::class)->cancel($order->fresh(), null, $owner);

        $this->assertSame(0, Order::withTrashed()->find($order->id)->paidNet());
        $this->assertSame(0, (int) Payment::income()->sum('amount'));
    }

    /** @test */
    public function order_yang_sudah_direfund_tidak_bisa_dibatalkan(): void
    {
        $owner   = $this->user('marketing');
        $order   = $this->makeOrder($owner);
        $payment = $this->addPayment($order, 'pending', 750000);

        $refund = Payment::create([
            'order_id'     => $order->id,
            'payment_type' => 'refund',
            'amount'       => 300000,
            'status'       => 'paid',
            'paid_at'      => '2026-08-03',
        ]);

        $this->assertFalse($order->fresh()->isCancellable());

        try {
            app(OrderCancellationService::class)->cancel($order->fresh(), null, $owner);
            $this->fail('Order yang sudah di-refund seharusnya tidak bisa dibatalkan.');
        } catch (\App\Exceptions\OrderCancellationException $e) {
            $this->assertStringContainsString('refund', $e->getMessage());
        }

        // Kedua entri kas nyata harus tetap utuh — uangnya benar-benar masuk lalu keluar.
        $this->assertDatabaseHas('tb_cash_entries', ['payment_id' => $payment->id]);
        $this->assertDatabaseHas('tb_cash_entries', ['payment_id' => $refund->id]);
        $this->assertDatabaseHas('tb_orders', ['id' => $order->id, 'deleted_at' => null]);
    }

    /** @test */
    public function tagihan_tertaut_kembali_ke_disetujui(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);

        $tagihan = Tagihan::create([
            'tagihan_no'   => 'TGH-0001',
            'created_by'   => $owner->id,
            'client_name'  => 'Klien A',
            'client_email' => 'klien@example.com',
            'title'        => 'Judul Uji',
            'type'         => 'bk_mandiri',
            'amount'       => 1000000,
            'status'       => 'jadi_order',
            'order_id'     => $order->id,
            'order_code'   => $order->code_order,
        ]);

        // Tagihan lain yang TIDAK berstatus jadi_order tidak boleh ikut tersentuh —
        // mengunci filter where('status', 'jadi_order') supaya tak hilang diam-diam.
        $lain = Tagihan::create([
            'tagihan_no'   => 'TGH-0002',
            'created_by'   => $owner->id,
            'client_name'  => 'Klien B',
            'client_email' => 'klienb@example.com',
            'title'        => 'Judul Lain',
            'type'         => 'bk_mandiri',
            'amount'       => 500000,
            'status'       => 'dibatalkan',
            'order_id'     => $order->id,
            'order_code'   => $order->code_order,
        ]);

        app(OrderCancellationService::class)->cancel($order, null, $owner);

        $tagihan->refresh();
        $this->assertSame('disetujui', $tagihan->status);
        $this->assertNull($tagihan->order_id);
        $this->assertNull($tagihan->order_code);
        $this->assertDatabaseHas('tb_tagihan_logs', [
            'tagihan_id'  => $tagihan->id,
            'from_status' => 'jadi_order',
            'to_status'   => 'disetujui',
        ]);

        $lain->refresh();
        $this->assertSame('dibatalkan', $lain->status);
        $this->assertSame($order->id, $lain->order_id);
    }

    /** @test */
    public function batal_ditolak_bila_periode_kas_terkunci(): void
    {
        $owner   = $this->user('marketing');
        $order   = $this->makeOrder($owner);
        $payment = $this->addPayment($order, 'pending');

        // Entri kas payment jatuh di Agustus 2026 (paid_at = 2026-08-02).
        CashPeriodLock::create([
            'year'      => 2026,
            'month'     => 8,
            'locked_by' => $this->user('superadmin')->id,
            'locked_at' => now(),
        ]);

        try {
            app(OrderCancellationService::class)->cancel($order->fresh(), null, $owner);
            $this->fail('Pembatalan seharusnya ditolak karena periode kas terkunci.');
        } catch (\App\Exceptions\OrderCancellationException $e) {
            $this->assertStringContainsString('08/2026', $e->getMessage());
        }

        // Tidak ada satu pun efek samping yang lolos.
        $this->assertDatabaseHas('tb_orders', ['id' => $order->id, 'deleted_at' => null]);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertDatabaseHas('tb_cash_entries', ['payment_id' => $payment->id]);
    }

    /**
     * @test
     *
     * Mengikuti pola NotificationHooksTest: Notification::fake() + assertSentTo,
     * bukan menghitung baris tb_notifications langsung.
     */
    public function pembatalan_memberi_tahu_manager_dan_superadmin(): void
    {
        $owner      = $this->user('marketing');
        $manager    = $this->user('manager');
        $superadmin = $this->user('superadmin');
        $order      = $this->makeOrder($owner);

        Notification::fake();

        app(OrderCancellationService::class)->cancel($order, 'Salah input', $owner);

        Notification::assertSentTo($manager, DatabaseNotification::class);
        Notification::assertSentTo($superadmin, DatabaseNotification::class);
        Notification::assertNotSentTo($owner, DatabaseNotification::class);
    }

    /** @test */
    public function marketing_pemilik_bisa_membatalkan_lewat_route(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);

        $this->actingAs($owner)
            ->delete(route('order.cancel', $order->code_order), ['cancel_reason' => 'Salah harga'])
            ->assertRedirect(route('order.book.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('tb_orders', ['id' => $order->id]);
        $this->assertSame('Salah harga', Order::withTrashed()->find($order->id)->cancel_reason);
    }

    /** @test */
    public function marketing_tidak_bisa_membatalkan_order_marketing_lain(): void
    {
        $owner = $this->user('marketing');
        $lain  = $this->user('marketing');
        $order = $this->makeOrder($owner);

        $this->actingAs($lain)
            ->delete(route('order.cancel', $order->code_order))
            ->assertForbidden();

        $this->assertDatabaseHas('tb_orders', ['id' => $order->id, 'deleted_at' => null]);
    }

    /** @test */
    public function production_tidak_punya_akses_pembatalan(): void
    {
        $order = $this->makeOrder($this->user('marketing'));

        // deleteJson (bukan delete): production tidak punya permission order.cancel
        // sama sekali, jadi ditolak EnforcePermission sebelum sampai controller. Untuk
        // request non-JSON di method HTTP tak-aman, EnforcePermission::deny() sengaja
        // redirect-back + flash error (bukan 403 mentah) supaya tidak menampilkan
        // halaman 403 di tengah alur form — lihat komentarnya dan pola yang sama di
        // AccessParityTest ("Request JSON (AJAX) -> 403 murni, bukan redirect").
        // Controller tidak pernah tersentuh di sini; ini murni gerbang permission.
        $this->actingAs($this->user('production'))
            ->deleteJson(route('order.cancel', $order->code_order))
            ->assertForbidden();
    }

    /** @test */
    public function route_batal_menolak_order_dengan_payment_disetujui(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        $this->addPayment($order, 'approved');

        $this->actingAs($owner)
            ->delete(route('order.cancel', $order->code_order))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('tb_orders', ['id' => $order->id, 'deleted_at' => null]);
    }

    /** @test */
    public function order_dibatalkan_tidak_muncul_di_daftar_default_tapi_muncul_di_daftar_trashed(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        app(OrderCancellationService::class)->cancel($order, null, $owner);

        $this->actingAs($owner)->get(route('order.book.index'))
            ->assertOk()->assertDontSee($order->code_order);

        $this->actingAs($owner)->get(route('order.book.index', ['trashed' => 1]))
            ->assertOk()->assertSee($order->code_order);
    }

    /** @test */
    public function hanya_manager_atau_superadmin_yang_bisa_memulihkan(): void
    {
        $owner = $this->user('marketing');
        $order = $this->makeOrder($owner);
        app(OrderCancellationService::class)->cancel($order, null, $owner);

        // postJson (bukan post): owner (marketing) tidak punya permission order.restore
        // sama sekali (sengaja tak dihibahkan ke siapa pun secara eksplisit — lihat
        // AccessMatrixSeeder). Sama seperti tes production di atas: butuh JSON supaya
        // EnforcePermission::deny() membalas 403 murni, bukan redirect-back.
        $this->actingAs($owner)
            ->postJson(route('order.restore', $order->code_order))
            ->assertForbidden();

        $this->actingAs($this->user('manager'))
            ->post(route('order.restore', $order->code_order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull(Order::find($order->id));
    }
}
