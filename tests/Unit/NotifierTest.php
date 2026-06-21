<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tagihan;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Notifications\DatabaseNotification;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

class NotifierTest extends TestCase
{
    use RefreshDatabase;

    private Notifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->notifier = new Notifier();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function payment(User $owner, int $amount = 500000, string $status = 'paid'): Payment
    {
        $order = Order::factory()->create(['user_id' => $owner->id]);
        return Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => $amount, 'paid_at' => now(), 'status' => $status]);
    }

    /** @test */
    public function payment_approved_notifies_owner_not_actor(): void
    {
        Notification::fake();
        $owner = $this->user('marketing');
        $actor = $this->user('superadmin');

        $this->notifier->paymentApproved($this->payment($owner), $actor);

        Notification::assertSentTo($owner, DatabaseNotification::class, fn ($n) =>
            $n->payload['category'] === 'payment' && str_contains($n->payload['title'], 'disetujui'));
        Notification::assertNotSentTo($actor, DatabaseNotification::class);
    }

    /** @test */
    public function payment_submitted_notifies_managers_and_superadmins_only(): void
    {
        Notification::fake();
        $marketing = $this->user('marketing');
        $manager   = $this->user('manager');
        $admin     = $this->user('superadmin');

        $this->notifier->paymentSubmitted($this->payment($marketing), $marketing);

        Notification::assertSentTo($manager, DatabaseNotification::class);
        Notification::assertSentTo($admin, DatabaseNotification::class);
        Notification::assertNotSentTo($marketing, DatabaseNotification::class);
    }

    /** @test */
    public function tagihan_approved_notifies_creator(): void
    {
        Notification::fake();
        $creator = $this->user('marketing');
        $actor   = $this->user('superadmin');
        $tagihan = Tagihan::factory()->create(['created_by' => $creator->id, 'status' => 'disetujui']);

        $this->notifier->tagihanApproved($tagihan, $actor);

        Notification::assertSentTo($creator, DatabaseNotification::class, fn ($n) => $n->payload['category'] === 'tagihan');
        Notification::assertNotSentTo($actor, DatabaseNotification::class);
    }

    /** @test */
    public function naskah_stage_changed_notifies_owner_with_deep_link(): void
    {
        Notification::fake();
        $owner = $this->user('marketing');
        $actor = $this->user('production');
        $order = Order::factory()->create(['user_id' => $owner->id]);
        $detail = OrderDetail::factory()->create(['order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Naskah X']);
        $progress = TitleProgress::create(['order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production', 'started_at' => now()]);

        $this->notifier->naskahStageChanged($progress, $actor, 'editing', 'layout');

        Notification::assertSentTo($owner, DatabaseNotification::class, fn ($n) =>
            $n->payload['category'] === 'naskah' && str_contains($n->payload['url'], (string) $progress->order_detail_id));
        Notification::assertNotSentTo($actor, DatabaseNotification::class);
    }

    /** @test */
    public function nothing_sent_when_no_recipients(): void
    {
        Notification::fake();
        $marketing = $this->user('marketing'); // tak ada manager/superadmin
        $this->notifier->paymentSubmitted($this->payment($marketing), $marketing);
        Notification::assertNothingSent();
    }
}
