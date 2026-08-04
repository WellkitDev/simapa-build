<?php

namespace App\Services;

use App\Models\MarketingTarget;
use App\Models\Order;
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

    public function refundIssued(Payment $payment, User $actor): void
    {
        $payment->loadMissing('order.user');
        $recipients = $this->roleUsers(['manager', 'superadmin'], $actor);
        $owner = $payment->order?->user;
        if ($owner && $owner->id !== $actor->id) {
            $recipients = $recipients->push($owner)->unique('id')->values();
        }
        $this->send($recipients, [
            'category' => 'payment',
            'title'    => 'Refund diproses',
            'message'  => 'Rp ' . $this->rp($payment->amount) . ' — ' . ($payment->order?->user?->name ?? '—'),
            'url'      => route('invoice.index'),
            'icon'     => 'corner-up-left',
        ]);
    }

    public function orderCancelled(Order $order, User $actor): void
    {
        $this->send($this->roleUsers(['manager', 'superadmin'], $actor), [
            'category' => 'order',
            'title'    => 'Order dibatalkan',
            'message'  => $order->code_order . ' dibatalkan oleh ' . $actor->name,
            'url'      => route('order.book.index', ['trashed' => 1]),
            'icon'     => 'x-octagon',
        ]);
    }

    public function orderRestored(Order $order, User $actor): void
    {
        $this->send($this->roleUsers(['manager', 'superadmin'], $actor), [
            'category' => 'order',
            'title'    => 'Order dipulihkan',
            'message'  => $order->code_order . ' dipulihkan oleh ' . $actor->name,
            'url'      => route('order.book.index'),
            'icon'     => 'rotate-ccw',
        ]);
    }

    public function salarySlipIssued(\App\Models\SalarySlip $slip): void
    {
        $slip->loadMissing('employee');
        if (! $slip->employee) {
            return;
        }
        $this->send(collect([$slip->employee]), [
            'category' => 'salary',
            'title'    => 'Slip gaji tersedia',
            'message'  => 'Periode ' . $slip->periodLabel() . ' • Rp ' . $this->rp($slip->net_pay),
            'url'      => route('salary.slip.me'),
            'icon'     => 'file-text',
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

    public function distribusiChanged(TitleProgress $progress, User $actor, string $summary): void
    {
        $progress->loadMissing('orderDetail');
        $judul = optional($progress->orderDetail)->title ?? '—';
        $this->send($this->roleUsers(['superadmin', 'manager', 'admin', 'production'], $actor), [
            'category' => 'manuscript',
            'title'    => 'Distribusi naskah diperbarui',
            'message'  => $summary . ' — ' . $judul,
            'url'      => route('manuscript.board'),
            'icon'     => 'layers',
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

    public function titleArchiveSubmitted(\App\Models\TitleArchive $archive, User $actor): void
    {
        $archive->loadMissing('title');
        $this->send($this->roleUsers(['superadmin', 'manager'], $actor), [
            'category' => 'title',
            'title'    => 'Judul diajukan ke arsip',
            'message'  => trim((optional($archive->title)->code ? $archive->title->code . ' — ' : '') . optional($archive->title)->title),
            'url'      => route('archive.show', $archive->title_id),
            'icon'     => 'archive',
        ]);
    }

    private function rp(int|string|null $amount): string
    {
        return number_format((int) $amount, 0, ',', '.');
    }

    /**
     * Users dengan salah satu role, kecuali aktor.
     * Saring dulu ke role yang benar-benar ada — `User::role()` (Spatie) melempar
     * RoleDoesNotExist bila salah satu nama role tak terdaftar, yang seharusnya tak
     * menjatuhkan alur (mis. perubahan tahap) hanya karena satu role belum dibuat.
     */
    private function roleUsers(array $roles, User $actor): Collection
    {
        $existing = \Spatie\Permission\Models\Role::whereIn('name', $roles)
            ->where('guard_name', 'web')->pluck('name')->all();

        if (empty($existing)) {
            return collect();
        }

        return User::role($existing)->get()->reject(fn (User $u) => $u->id === $actor->id)->values();
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
