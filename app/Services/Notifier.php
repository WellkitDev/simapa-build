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
use Spatie\Permission\Models\Role;

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

    // ─── Penugasan Naskah ───

    /** Pelaksana ditunjuk admin — yang perlu tahu duluan adalah orang yang mengerjakan. */
    public function naskahDistribusi(TitleProgress $progress, User $pelaksana, User $actor): void
    {
        $progress->loadMissing('orderDetail');
        $this->toOwner($pelaksana, $actor, [
            'category' => 'naskah',
            'title'    => 'Tugas naskah baru untukmu',
            'message'  => $this->naskahLabel($progress)
                          . ($progress->sla_due_at ? ' • jatuh tempo ' . $progress->sla_due_at->translatedFormat('j M Y') : ''),
            'url'      => $this->naskahUrl($progress),
            'icon'     => 'user-check',
        ]);
    }

    /** Produksi mengambil tugas sendiri — PJ & admin bidang perlu tahu antrian berkurang. */
    public function naskahClaimed(TitleProgress $progress, User $actor): void
    {
        $progress->loadMissing('orderDetail');

        $recipients = $this->bidangAdmins($progress, $actor);
        if ($progress->pj && $progress->pj->id !== $actor->id) {
            $recipients = $recipients->push($progress->pj)->unique('id')->values();
        }

        $this->send($recipients, [
            'category' => 'naskah',
            'title'    => 'Tugas naskah diambil',
            'message'  => $this->naskahLabel($progress) . ' • diambil ' . $actor->name,
            'url'      => $this->naskahUrl($progress),
            'icon'     => 'hand',
        ]);
    }

    /** Naskah maju sendiri karena pelaksana mengunggah hasilnya — PJ yang harus tahu. */
    public function naskahAutoAdvanced(TitleProgress $progress, User $uploader): void
    {
        $progress->loadMissing(['orderDetail', 'pj']);
        $this->toOwner($progress->pj, $uploader, [
            'category' => 'naskah',
            'title'    => 'Naskah maju otomatis ke ' . TitleProgress::labelFor($progress->status),
            'message'  => $this->naskahLabel($progress) . ' • naskah diunggah ' . $uploader->name,
            'url'      => $this->naskahUrl($progress),
            'icon'     => 'upload-cloud',
        ]);
    }

    /** Tanggung jawab proses berpindah — admin penerima yang wajib tahu. */
    public function naskahPjTransferred(TitleProgress $progress, User $penerima, User $actor): void
    {
        $progress->loadMissing('orderDetail');
        $this->toOwner($penerima, $actor, [
            'category' => 'naskah',
            'title'    => 'Kamu jadi PJ naskah ini',
            'message'  => $this->naskahLabel($progress),
            'url'      => $this->naskahUrl($progress),
            'icon'     => 'users',
        ]);
    }

    /** Tugas ditarik kembali — pelaksana yang kehilangan tugas harus tahu. */
    public function naskahWithdrawn(TitleProgress $progress, User $pelaksana, User $actor): void
    {
        $progress->loadMissing('orderDetail');
        $this->toOwner($pelaksana, $actor, [
            'category' => 'naskah',
            'title'    => 'Tugas naskah ditarik',
            'message'  => $this->naskahLabel($progress) . ' • ditarik ' . $actor->name,
            'url'      => $this->naskahUrl($progress),
            'icon'     => 'corner-up-left',
        ]);
    }

    /**
     * Perpindahan tahap (maju maupun koreksi). Penerima = PJ + superadmin, sesuai
     * keputusan "tanpa approval, hanya notifikasi". Marketing TIDAK dikabari tiap
     * tahap — mereka menerima kabar saat publish/terbit lewat naskahPublished().
     */
    public function naskahTahapBerubah(TitleProgress $progress, User $actor, string $from, string $to, bool $isCorrection = false): void
    {
        $progress->loadMissing(['orderDetail', 'pj', 'pelaksana']);

        $stages = $progress->getStages();
        $mundur = array_search($to, $stages, true) < array_search($from, $stages, true);

        $recipients = $this->roleUsers(['superadmin'], $actor);
        if ($progress->pj && $progress->pj->id !== $actor->id) {
            $recipients = $recipients->push($progress->pj);
        }
        // Perpindahan mundur ditujukan kepada pelaksana — dialah yang harus mengerjakan
        // ulang. Sebelum ini ia tak pernah dikabari sama sekali.
        if ($mundur && $progress->pelaksana && $progress->pelaksana->id !== $actor->id) {
            $recipients = $recipients->push($progress->pelaksana);
        }
        $recipients = $recipients->unique('id')->values();

        $judul = match (true) {
            $isCorrection => 'Koreksi tahap: ',
            $mundur       => 'Naskah dikembalikan ke ',
            default       => 'Naskah maju ke ',
        };

        $this->send($recipients, [
            'category' => 'naskah',
            'title'    => $judul . TitleProgress::labelFor($to),
            'message'  => $this->naskahLabel($progress) . ' • dari '
                          . TitleProgress::labelFor($from) . ' oleh ' . $actor->name,
            'url'      => $this->naskahUrl($progress),
            'icon'     => ($isCorrection || $mundur) ? 'rotate-ccw' : 'arrow-right-circle',
        ]);
    }

    /**
     * Permintaan revisi ditujukan ke SATU orang: pelaksana yang harus mengerjakannya.
     * Tanpa ini, "ditujukan untuk Pelaksana" cuma teks di layar yang tak pernah
     * benar-benar sampai kepadanya.
     */
    public function naskahRevisiDiminta(\App\Models\ManuscriptRevision $putaran, User $actor): void
    {
        $putaran->loadMissing(['assignedTo', 'title']);

        if (! $putaran->assignedTo || $putaran->assignedTo->id === $actor->id) {
            return;
        }

        $this->send(collect([$putaran->assignedTo]), [
            'category' => 'naskah',
            'title'    => "Permintaan revisi putaran ke-{$putaran->round}",
            'message'  => ($putaran->title?->title ?? 'Naskah') . ' • ' . $putaran->request_note
                          . ' • diminta ' . $actor->name,
            'url'      => route('naskah.pelacakan'),
            'icon'     => 'edit-3',
        ]);
    }

    /**
     * Naskah terbit/publish — marketing pemilik order inilah yang mengabari klien.
     * Dipanggil per order dalam grup supaya tiap pemilik dapat kabarnya sendiri.
     */
    public function naskahPublished(TitleProgress $progress, User $actor): void
    {
        $progress->loadMissing('orderDetail.order.user');
        $this->toOwner($progress->orderDetail?->order?->user, $actor, [
            'category' => 'naskah',
            'title'    => 'Naskah ' . TitleProgress::labelFor($progress->status) . ' — bisa dikabari ke klien',
            'message'  => $this->naskahLabel($progress),
            'url'      => $this->naskahUrl($progress),
            'icon'     => 'check-circle',
        ]);
    }

    /**
     * Lewat SLA pembuatan atau target publish/terbit (dipicu command harian).
     * Penerima = PJ + pelaksana + superadmin; aktor sistem, jadi tak ada yang dikecualikan.
     */
    public function naskahOverdue(TitleProgress $progress): void
    {
        $progress->loadMissing(['orderDetail', 'pj', 'pelaksana']);

        $recipients = Role::where('name', 'superadmin')->where('guard_name', 'web')->exists()
            ? User::role('superadmin')->get()
            : collect();

        foreach ([$progress->pj, $progress->pelaksana] as $orang) {
            if ($orang) {
                $recipients = $recipients->push($orang);
            }
        }

        $tenggat = $progress->status === 'pembuatan' ? $progress->sla_due_at : $progress->target_date;

        $this->send($recipients->unique('id')->values(), [
            'category' => 'naskah',
            'title'    => 'Naskah lewat tenggat',
            'message'  => $this->naskahLabel($progress) . ' • ' . TitleProgress::labelFor($progress->status)
                          . ($tenggat ? ' • jatuh tempo ' . $tenggat->translatedFormat('j M Y') : ''),
            'url'      => $this->naskahUrl($progress),
            'icon'     => 'alert-triangle',
        ]);
    }

    /** Identitas naskah di notifikasi = kode order, judul sebagai pendamping. */
    private function naskahLabel(TitleProgress $progress): string
    {
        $detail = $progress->orderDetail;
        $kode   = $detail?->order?->code_order ?? $detail?->titleRef?->code;

        return trim(($kode ? $kode . ' — ' : '') . ($detail?->title ?? 'Naskah'));
    }

    /**
     * Admin pemegang bidang naskah. Admin tanpa bidang ikut menerima — bidang belum
     * punya layar pengisian, jadi menyaringnya ketat justru membuat notifikasi hilang.
     */
    private function bidangAdmins(TitleProgress $progress, User $actor): Collection
    {
        return $this->roleUsers(['admin'], $actor)
            ->filter(fn (User $u) => $progress->bidang === null
                || $u->profile?->bidang === null
                || $u->profile?->bidang === $progress->bidang)
            ->values();
    }

    /** URL kanonik naskah — halaman Detail Naskah, tempat semua aksi berada. */
    private function naskahUrl(TitleProgress $progress): string
    {
        return route('naskah.show', $progress->order_detail_id);
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
        $existing = Role::whereIn('name', $roles)
            ->where('guard_name', 'web')->pluck('name')->all();

        if (empty($existing)) {
            return collect();
        }

        return User::role($existing)->get()->reject(fn (User $u) => $u->id === $actor->id)->values();
    }

    /**
     * Unggahan berkas ke Drive gagal di queue.
     *
     * Sejak unggahan pindah ke belakang layar, kegagalannya tak lagi muncul sebagai
     * galat di layar orang yang mengunggah — tanpa notifikasi ini ia hanya mengendap
     * di failed_jobs, dan berkasnya tak pernah ada tanpa ada yang tahu.
     *
     * Penerimanya pengunggah DAN superadmin: pengunggah karena dialah yang perlu
     * mengulang, superadmin karena kegagalan beruntun biasanya berarti token Drive
     * bermasalah, bukan berkasnya.
     */
    public function unggahanGagal(\App\Models\ManuscriptFile $berkas, string $sebab): void
    {
        $penerima = User::query()
            ->where('id', $berkas->uploaded_by)
            ->orWhereHas('roles', fn ($q) => $q->where('name', 'superadmin'))
            ->get();

        $this->send($penerima, [
            'category' => 'naskah',
            'title'    => 'Unggahan berkas gagal',
            'message'  => sprintf('%s (%s) — %s. Berkasnya masih tersimpan, coba unggah ulang.',
                $berkas->original_name, $berkas->slotLabel(), \Illuminate\Support\Str::limit($sebab, 120)),
            'url'      => route('title.show', $berkas->title_id),
            'icon'     => 'alert-triangle',
        ]);
    }

    /**
     * Job pengiriman email gagal setelah semua percobaan habis.
     *
     * Tanpa ini kegagalan berhenti di tabel failed_jobs — tempat yang tak pernah
     * dibuka siapa pun — sehingga invoice atau slip gaji tercatat "terkirim" di layar
     * padahal tak pernah sampai, dan yang pertama tahu adalah penerimanya.
     *
     * Penerima notifikasi = superadmin: kegagalan email hampir selalu urusan setelan
     * (SMTP, kuota, alamat salah), bukan sesuatu yang bisa diperbaiki pemakai biasa.
     */
    public function pengirimanGagal(string $apa, ?string $rujukan, string $sebab, ?string $url = null): void
    {
        $penerima = User::whereHas('roles', fn ($q) => $q->where('name', 'superadmin'))->get();

        $this->send($penerima, [
            'category' => 'sistem',
            'title'    => 'Pengiriman email gagal',
            'message'  => trim(sprintf('%s%s gagal dikirim — %s',
                $apa,
                $rujukan ? ' ' . $rujukan : '',
                \Illuminate\Support\Str::limit($sebab, 160))),
            'url'      => $url ?? route('dashboard'),
            'icon'     => 'alert-octagon',
        ]);
    }

    /**
     * Invoice lewat jatuh tempo, ditandai otomatis tiap pagi.
     *
     * Penerimanya pemilik order (marketing) — dialah yang menagih. Sengaja BUKAN
     * klien: mengirim email otomatis ke klien adalah keputusan bisnis yang belum
     * diambil siapa pun.
     */
    public function invoiceJatuhTempo(\App\Models\Invoice $invoice): void
    {
        $invoice->loadMissing('order.user');
        $pemilik = $invoice->order?->user;
        if (! $pemilik) {
            return;
        }

        $this->send(collect([$pemilik]), [
            'category' => 'invoice',
            'title'    => 'Invoice lewat jatuh tempo',
            'message'  => $invoice->invoice_no . ' jatuh tempo ' . $invoice->due_at->format('d M Y'),
            'url'      => route('invoice.show', $invoice->id),
            'icon'     => 'alert-circle',
        ]);
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
