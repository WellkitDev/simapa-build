<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tagihan;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\PaymentApproval;
use App\Services\GoogleDriveService;
use App\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

class NotificationHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function approving_payment_notifies_owner(): void
    {
        $owner = $this->user('marketing');
        $admin = $this->user('superadmin');
        $order = Order::factory()->create(['user_id' => $owner->id]);
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 500000, 'paid_at' => now(), 'status' => 'pending']);
        PaymentApproval::create(['payment_id' => $payment->id, 'status' => 'pending']);

        Notification::fake();
        $this->actingAs($admin)->post(route('payment.approve', $payment->id));

        Notification::assertSentTo($owner, DatabaseNotification::class);
    }

    /** @test */
    public function approving_tagihan_notifies_creator(): void
    {
        $creator = $this->user('marketing');
        $admin = $this->user('superadmin');
        $tagihan = Tagihan::factory()->create(['created_by' => $creator->id, 'status' => 'diajukan']);

        Notification::fake();
        $this->actingAs($admin)->post(route('tagihan.approve', $tagihan->id));

        Notification::assertSentTo($creator, DatabaseNotification::class);
    }

    /** @test */
    public function advancing_naskah_notifies_owner(): void
    {
        $owner = $this->user('marketing');
        $manager = $this->user('manager');
        $order = Order::factory()->create(['user_id' => $owner->id]);
        $detail = OrderDetail::factory()->create(['order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Naskah Z']);
        $progress = TitleProgress::create(['order_detail_id' => $detail->id, 'status' => 'menunggu_proses', 'assigned_role' => 'marketing', 'started_at' => now()]);

        Notification::fake();
        $this->actingAs($manager)->post(route('title.progress.update', $progress->id), ['status' => 'editing']);

        Notification::assertSentTo($owner, DatabaseNotification::class);
    }
}
