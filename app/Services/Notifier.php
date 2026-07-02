<?php

namespace App\Services;

use App\Models\MarketingTarget;
use App\Models\Payment;
use App\Models\Tagihan;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class Notifier
{
    public function paymentSubmitted(Payment $payment, User $actor): void
    {
        $payment->loadMissing('order.user');
        $this->send($this->roleUsers(['manager', 'superadmin'], $actor), [
            'category' => 'payment',
            'title'    => 'Pembayaran menunggu persetujuan',
            'message'  => 'Rp ' . $this->rp($payment->amount) . ' dari ' . ($payment->order?->user?->name ?? '—'),
            'url'      => route('payment.index'),
            'icon'     => 'credit-card',
        ]);
    }

    public function paymentApproved(Payment $payment, User $actor): void
    {
        $payment->loadMissing('order.user');
        $this->toOwner($payment->order?->user, $actor, [
            'category' => 'payment',
            'title'    => 'Pembayaran disetujui',
            'message'  => 'Rp ' . $this->rp($payment->amount),
            'url'      => route('payment.index'),
            'icon'     => 'check-circle',
        ]);
    }

    public function paymentRejected(Payment $payment, User $actor): void
    {
        $payment->loadMissing('order.user');
        $this->toOwner($payment->order?->user, $actor, [
            'category' => 'payment',
            'title'    => 'Pembayaran ditolak',
            'message'  => 'Rp ' . $this->rp($payment->amount),
            'url'      => route('payment.index'),
            'icon'     => 'x-circle',
        ]);
    }

    public function tagihanSubmitted(Tagihan $tagihan, User $actor): void
    {
        $this->send($this->roleUsers(['superadmin'], $actor), [
            'category' => 'tagihan',
            'title'    => 'Tagihan menunggu persetujuan',
            'message'  => $tagihan->title . ' • Rp ' . $this->rp($tagihan->amount),
            'url'      => route('tagihan.show', $tagihan->id),
            'icon'     => 'file-text',
        ]);
    }

    public function tagihanApproved(Tagihan $tagihan, User $actor): void
    {
        $tagihan->loadMissing('creator');
        $this->toOwner($tagihan->creator, $actor, [
            'category' => 'tagihan',
            'title'    => 'Tagihan disetujui',
            'message'  => $tagihan->title,
            'url'      => route('tagihan.show', $tagihan->id),
            'icon'     => 'check-circle',
        ]);
    }

    public function tagihanRejected(Tagihan $tagihan, User $actor): void
    {
        $tagihan->loadMissing('creator');
        $this->toOwner($tagihan->creator, $actor, [
            'category' => 'tagihan',
            'title'    => 'Tagihan ditolak',
            'message'  => $tagihan->title . ($tagihan->reject_note ? ' — ' . $tagihan->reject_note : ''),
            'url'      => route('tagihan.show', $tagihan->id),
            'icon'     => 'x-circle',
        ]);
    }

    public function naskahStageChanged(TitleProgress $progress, User $actor, string $from, string $to): void
    {
        $progress->loadMissing('orderDetail.order.user');
        $tahap = Str::title(str_replace('_', ' ', $to));
        $this->toOwner($progress->orderDetail?->order?->user, $actor, [
            'category' => 'naskah',
            'title'    => 'Naskah maju ke ' . $tahap,
            'message'  => $progress->orderDetail?->title ?? 'Naskah',
            'url'      => route('order.indexJudul.progress', $progress->order_detail_id),
            'icon'     => 'book-open',
        ]);
    }

    public function targetAssigned(MarketingTarget $target, User $actor): void
    {
        $target->loadMissing('user');
        $this->toOwner($target->user, $actor, [
            'category' => 'target',
            'title'    => 'Target baru ditetapkan',
            'message'  => 'Periode ' . ($target->start_date?->format('d M') ?? '?') . ' – ' . ($target->end_date?->format('d M Y') ?? '?')
                          . ' • Target Rp ' . $this->rp($target->target_amount),
            'url'      => route('marketing-target.me'),
            'icon'     => 'target',
        ]);
    }

    public function commissionPaid(MarketingTarget $target, User $actor): void
    {
        $target->loadMissing('user');
        $this->toOwner($target->user, $actor, [
            'category' => 'target',
            'title'    => 'Komisi target ditandai dibayar',
            'message'  => 'Periode ' . ($target->start_date?->format('d M') ?? '?') . ' – ' . ($target->end_date?->format('d M Y') ?? '?'),
            'url'      => route('marketing-target.me'),
            'icon'     => 'check-circle',
        ]);
    }

    public function taskAssigned(Task $task, User $actor): void
    {
        $task->loadMissing('user');
        $this->toOwner($task->user, $actor, [
            'category' => 'task',
            'title'    => 'Tugas baru ditugaskan',
            'message'  => $task->title,
            'url'      => route('task.board'),
            'icon'     => 'check-square',
        ]);
    }

    public function deadlineReminder(Task $task): void
    {
        $task->loadMissing('user');
        if (! $task->user) {
            return;
        }
        $recipients = $this->roleUsers(['manager', 'superadmin', 'admin'], $task->user)
            ->push($task->user)->unique('id')->values();

        $this->send($recipients, [
            'category' => 'deadline',
            'title'    => 'Tugas mendekati deadline',
            'message'  => $task->title . ' • ' . ($task->due_date?->format('d M Y') ?? '?'),
            'url'      => route('task.board'),
            'icon'     => 'clock',
        ]);
    }

    public function naskahNeedsReview(TitleProgress $progress, User $actor): void
    {
        $progress->loadMissing('orderDetail');
        $this->send($this->roleUsers(['manager', 'superadmin'], $actor), [
            'category' => 'naskah',
            'title'    => 'Naskah perlu ditinjau',
            'message'  => $progress->orderDetail?->title ?? 'Naskah',
            'url'      => route('order.indexJudul.progress', $progress->order_detail_id),
            'icon'     => 'alert-triangle',
        ]);
    }

    public function titleInfoUpdated(Title $title, User $actor): void
    {
        $this->send($this->roleUsers(['superadmin'], $actor), [
            'category' => 'title',
            'title'    => 'Info publikasi judul diperbarui',
            'message'  => trim(($title->code ? $title->code . ' — ' : '') . $title->title),
            'url'      => route('title.show', $title->id),
            'icon'     => 'edit',
        ]);
    }

    private function rp(int|string|null $amount): string
    {
        return number_format((int) $amount, 0, ',', '.');
    }

    /** Users dengan salah satu role, kecuali aktor. */
    private function roleUsers(array $roles, User $actor): Collection
    {
        return User::role($roles)->get()->reject(fn (User $u) => $u->id === $actor->id)->values();
    }

    private function toOwner(?User $owner, User $actor, array $payload): void
    {
        if (! $owner || $owner->id === $actor->id) {
            return;
        }
        $this->send(collect([$owner]), $payload);
    }

    private function send(Collection $recipients, array $payload): void
    {
        if ($recipients->isEmpty()) {
            return;
        }
        try {
            Notification::send($recipients, new DatabaseNotification($payload));
        } catch (\Throwable $e) {
            Log::warning('Notifier gagal mengirim notifikasi: ' . $e->getMessage());
        }
    }
}
