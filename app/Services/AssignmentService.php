<?php

namespace App\Services;

use App\Models\ChapterProgress;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Models\User;
use App\Services\Concerns\AuthorizesNaskah;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penugasan naskah: siapa mengerjakan (Pelaksana) dan siapa bertanggung jawab (PJ).
 *
 * Dua peran, aturan berbeda:
 *  - Pelaksana (`pelaksana_user_id`) HARUS akun Produksi. Ditunjuk admin (distribute)
 *    atau diambil sendiri dari antrian (claim). Orang rangkap admin+produksi memakai
 *    dua akun terpisah — permintaan tim, bukan batasan teknis.
 *  - PJ (`pj_user_id`) HARUS akun Admin, dioper hanya antar admin sebidang.
 *
 * Aksi level judul berlaku SEREMPAK untuk semua order sejudul (`group_key`) dan
 * mengembalikan jumlah order terdampak, supaya UI bisa berkata "berlaku untuk N order".
 * Aksi level bab berdiri sendiri (satu bab = satu penugasan).
 */
class AssignmentService
{
    use AuthorizesNaskah;

    /** SLA pembuatan naskah = 7 hari kerja (keputusan tim, F23). */
    public const SLA_WORKDAYS = 7;

    public function __construct(private Notifier $notifier) {}

    // ─── Distribusi & claim ───

    /**
     * Tunjuk pelaksana pembuatan naskah/bab. Bila naskah masih di antrian, distribusi
     * inilah yang memasukkannya ke tahap Pembuatan sekaligus memasang SLA — sesuai
     * riwayat di wireframe ("Bab 3 masuk Pembuatan (distribusi ke Fitri, SLA 7 hari)").
     *
     * @return int jumlah order terdampak (bab selalu 1)
     */
    public function distribute(TitleProgress|ChapterProgress $target, int $pelaksanaId, User $actor): int
    {
        $this->requirePermission($actor, 'naskah.assign');

        $pelaksana = User::find($pelaksanaId);
        if (! $pelaksana || ! $pelaksana->hasRole('production')) {
            throw ValidationException::withMessages([
                'pelaksana_user_id' => 'Pelaksana harus akun Produksi.',
            ]);
        }

        if ($target instanceof ChapterProgress) {
            $this->requireBidang($actor, 'buku');
            $this->assertChapterHasAuthor($target);
            $this->assertChapterButuhPelaksana($target);

            return $this->applyChapterAssignment($target, $pelaksana, $actor, 'distribusi');
        }

        $this->requireBidang($actor, $target->bidang);

        return $this->onGroup($target, function (TitleProgress $p) use ($pelaksana, $actor) {
            $from = $p->pelaksana?->name ?? '—';
            $this->startPembuatan($p, $pelaksana->id);
            $this->log($p, 'distribusi', $from, $pelaksana->name, $actor, $this->slaNote($p));
        }, fn (TitleProgress $p) => $this->notifier->naskahDistribusi($p, $pelaksana, $actor));
    }

    /**
     * Produksi mengambil sendiri tugas dari antrian. Hanya naskah/bab yang benar-benar
     * belum berpelaksana — kalau sudah ada, penugasan ulang adalah wewenang admin.
     */
    public function claim(TitleProgress|ChapterProgress $target, User $actor): int
    {
        $this->requirePermission($actor, 'naskah.claim');

        if ($target->pelaksana_user_id !== null) {
            throw ValidationException::withMessages([
                'pelaksana_user_id' => 'Tugas ini sudah ada pelaksananya.',
            ]);
        }

        if ($target instanceof ChapterProgress) {
            $this->assertChapterHasAuthor($target);
            $this->assertChapterButuhPelaksana($target);

            return $this->applyChapterAssignment($target, $actor, $actor, 'claim');
        }

        return $this->onGroup($target, function (TitleProgress $p) use ($actor) {
            $this->startPembuatan($p, $actor->id);
            $this->log($p, 'claim', '—', $actor->name, $actor, $this->slaNote($p));
        }, fn (TitleProgress $p) => $this->notifier->naskahClaimed($p, $actor));
    }

    /** Tarik tugas dari pelaksana. Naskah kembali ke antrian (tanpa memundurkan tahap). */
    public function withdraw(TitleProgress|ChapterProgress $target, User $actor): int
    {
        $this->requirePermission($actor, 'naskah.assign');

        if ($target instanceof ChapterProgress) {
            $this->requireBidang($actor, 'buku');
            $from = $target->pelaksana?->name ?? '—';
            $target->update(['pelaksana_user_id' => null, 'sla_due_at' => null]);
            $this->logChapter($target, 'tarik_tugas', $from, '—', $actor, null);

            return 1;
        }

        $this->requireBidang($actor, $target->bidang);
        $sebelumnya = $target->pelaksana;

        return $this->onGroup($target, function (TitleProgress $p) use ($actor) {
            $from = $p->pelaksana?->name ?? '—';
            $p->update(['pelaksana_user_id' => null, 'sla_due_at' => null]);
            $this->log($p, 'tarik_tugas', $from, '—', $actor, null);
        }, $sebelumnya
            ? fn (TitleProgress $p) => $this->notifier->naskahWithdrawn($p, $sebelumnya, $actor)
            : null);
    }

    // ─── Penanggung jawab ───

    /** Oper tanggung jawab proses ke admin lain. Lintas bidang hanya boleh superadmin. */
    public function transferPj(TitleProgress $progress, int $adminId, User $actor): int
    {
        $this->requirePermission($actor, 'naskah.assign');
        $this->requireBidang($actor, $progress->bidang);

        $admin = User::find($adminId);
        if (! $admin || ! $admin->hasRole('admin')) {
            throw ValidationException::withMessages([
                'pj_user_id' => 'Penanggung jawab harus akun Admin.',
            ]);
        }

        if (! $actor->hasRole('superadmin')) {
            $adminBidang = $admin->profile?->bidang;
            if ($progress->bidang !== null && $adminBidang !== null && $adminBidang !== $progress->bidang) {
                throw ValidationException::withMessages([
                    'pj_user_id' => 'PJ hanya bisa dioper ke admin bidang yang sama.',
                ]);
            }
        }

        return $this->onGroup($progress, function (TitleProgress $p) use ($admin, $actor) {
            $from = $p->pj?->name ?? '—';
            $p->update(['pj_user_id' => $admin->id]);
            $this->log($p, 'oper_pj', $from, $admin->name, $actor, null);
        }, fn (TitleProgress $p) => $this->notifier->naskahPjTransferred($p, $admin, $actor));
    }

    // ─── Hold & batal ───

    public function hold(TitleProgress $progress, User $actor, ?string $reason = null): int
    {
        return $this->setHold($progress, $actor, true, $reason);
    }

    public function unhold(TitleProgress $progress, User $actor, ?string $reason = null): int
    {
        return $this->setHold($progress, $actor, false, $reason);
    }

    /** Batalkan naskah: alasan WAJIB, hilang dari papan tapi tetap ada di arsip. */
    public function cancel(TitleProgress $progress, User $actor, string $reason): int
    {
        $this->requirePermission($actor, 'naskah.cancel');
        $this->requireBidang($actor, $progress->bidang);

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'cancel_reason' => 'Alasan pembatalan wajib diisi.',
            ]);
        }

        return $this->onGroup($progress, function (TitleProgress $p) use ($actor, $reason) {
            $p->update([
                'cancelled_at'  => now(),
                'cancelled_by'  => $actor->id,
                'cancel_reason' => $reason,
            ]);
            $this->log($p, 'dibatalkan', $p->stageLabelId(), 'Dibatalkan', $actor, $reason);
        });
    }

    // ─── Helper publik ───

    /** Tanggal jatuh tempo n hari KERJA ke depan (Sabtu–Minggu dilewati). */
    public static function addWorkdays(Carbon $from, int $days): Carbon
    {
        $date  = $from->copy()->startOfDay();
        $added = 0;

        while ($added < $days) {
            $date->addDay();
            if (! $date->isWeekend()) {
                $added++;
            }
        }

        return $date;
    }

    // ─── Internal ───

    private function setHold(TitleProgress $progress, User $actor, bool $onHold, ?string $reason): int
    {
        $this->requirePermission($actor, 'naskah.hold');
        $this->requireBidang($actor, $progress->bidang);

        $event = $onHold ? 'hold' : 'unhold';

        return $this->onGroup($progress, function (TitleProgress $p) use ($actor, $onHold, $reason, $event) {
            $p->update(['is_on_hold' => $onHold]);
            $this->log($p, $event, $onHold ? 'Berjalan' : 'Ditahan', $onHold ? 'Ditahan' : 'Berjalan', $actor, $reason);
        });
    }

    /**
     * Pasang pelaksana. Naskah yang masih di antrian sekaligus masuk tahap Pembuatan
     * dengan SLA baru; naskah yang sudah lewat tahap itu hanya berganti pelaksana.
     */
    private function startPembuatan(TitleProgress $progress, int $pelaksanaId): void
    {
        $attrs = ['pelaksana_user_id' => $pelaksanaId];

        if ($progress->status === 'menunggu_proses') {
            $attrs['status']        = 'pembuatan';
            $attrs['assigned_role'] = TitleProgress::getHandlerForStatus('pembuatan');
            $attrs['started_at']    = now();
        }

        if (($attrs['status'] ?? $progress->status) === 'pembuatan') {
            $attrs['sla_due_at'] = self::addWorkdays(now(), self::SLA_WORKDAYS);
        }

        $progress->update($attrs);
    }

    private function applyChapterAssignment(ChapterProgress $cp, User $pelaksana, User $actor, string $event): int
    {
        $from  = $cp->pelaksana?->name ?? '—';
        $attrs = ['pelaksana_user_id' => $pelaksana->id];

        if ($cp->status === 'menunggu') {
            $attrs['status']     = 'pembuatan';
            $attrs['started_at'] = now();
        }

        if (($attrs['status'] ?? $cp->status) === 'pembuatan') {
            $attrs['sla_due_at'] = self::addWorkdays(now(), self::SLA_WORKDAYS);
        }

        DB::transaction(function () use ($cp, $attrs, $event, $from, $pelaksana, $actor) {
            $cp->update($attrs);
            $this->logChapter($cp, $event, $from, $pelaksana->name, $actor, $this->slaNoteFor($cp->fresh()->sla_due_at));
        });

        return 1;
    }

    /**
     * Jalankan aksi untuk SELURUH order sejudul dalam satu transaksi, lalu kirim
     * notifikasi setelah commit (satu kali, mewakili grup).
     *
     * @return int jumlah order terdampak
     */
    private function onGroup(TitleProgress $progress, \Closure $apply, ?\Closure $notify = null): int
    {
        $group = $this->group($progress);

        DB::transaction(function () use ($group, $apply) {
            foreach ($group as $p) {
                $apply($p);
            }
        });

        if ($notify !== null) {
            $notify($group->first());
        }

        return $group->count();
    }

    /** @return Collection<int,TitleProgress> */
    private function group(TitleProgress $progress): Collection
    {
        $key = $progress->orderDetail?->group_key;
        if ($key === null) {
            return collect([$progress]);
        }

        return TitleProgress::with(['orderDetail', 'pj', 'pelaksana'])
            ->whereNull('withdrawn_at')
            ->whereHas('orderDetail', fn ($q) => $q->where('group_key', $key))
            ->get();
    }

    private function assertChapterHasAuthor(ChapterProgress $cp): void
    {
        if (! $cp->chapter?->authors()->exists()) {
            throw ValidationException::withMessages([
                'author' => 'Petakan author bab terlebih dahulu.',
            ]);
        }
    }

    /**
     * Bab yang naskahnya dikirim authornya sendiri tidak boleh ditugaskan ke pelaksana.
     * Tanpa gerbang ini, pelaksana bisa diminta menulis naskah yang sebenarnya sudah ada
     * — kerja ganda, dan naskah tim berpotensi menggantikan naskah asli author.
     */
    private function assertChapterButuhPelaksana(ChapterProgress $cp): void
    {
        if ($cp->naskahDariAuthor()) {
            throw ValidationException::withMessages([
                'pelaksana_user_id' => 'Bab ini bernaskah mandiri — naskahnya dikirim author, '
                    . 'jadi tidak perlu pelaksana. Cukup unggah naskah dari author.',
            ]);
        }
    }

    private function slaNote(TitleProgress $progress): ?string
    {
        return $this->slaNoteFor($progress->fresh()->sla_due_at);
    }

    private function slaNoteFor(?Carbon $due): ?string
    {
        if ($due === null) {
            return null;
        }

        return 'Masuk tahap Pembuatan Naskah · SLA ' . self::SLA_WORKDAYS
             . ' hari kerja, jatuh tempo ' . $due->translatedFormat('j M Y') . '.';
    }

    private function log(TitleProgress $progress, string $event, ?string $from, ?string $to, User $actor, ?string $note): void
    {
        TitleProgressLog::create([
            'title_progress_id' => $progress->id,
            'event'             => $event,
            'from_value'        => $from,
            'to_value'          => $to,
            'changed_by'        => $actor->id,
            'note'              => $note,
            'is_correction'     => false,
        ]);

        $progress->update(['last_log_at' => now()]);
    }

    /** Riwayat bab menempel pada TitleProgress buku (satu linimasa per naskah). */
    private function logChapter(ChapterProgress $cp, string $event, ?string $from, ?string $to, User $actor, ?string $note): void
    {
        $progress = $cp->chapter?->title?->orderDetails()->notWithdrawn()->with('titleProgress')->get()
            ->map->titleProgress->filter()->first();

        if (! $progress) {
            return;
        }

        $label = $cp->chapter->judul;
        $this->log($progress, $event, $from, "{$label}: {$to}", $actor, $note);
    }
}
