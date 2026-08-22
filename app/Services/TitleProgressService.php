<?php
// app/Services/TitleProgressService.php

namespace App\Services;

use App\Models\ChapterProgress;
use App\Models\ManuscriptRevision;
use App\Models\OrderDetail;
use App\Models\User;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Services\Concerns\AuthorizesNaskah;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TitleProgressService
{
    use AuthorizesNaskah;

    // ─────────────────────────────────────────────────────────────────────────
    // Penugasan Naskah v2: maju SATU langkah (advance) vs koreksi (correct).
    // Jalur lama changeStatus()/changeGroupStatus() di bawah masih melayani modul
    // Distribusi Artikel/Buku sampai cutover — sengaja tidak diubah.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Selesaikan tahap berjalan: target SELALU tahap berikutnya. Tidak ada dropdown
     * semua-tahap — mundur/lompat adalah wewenang correct(). Catatan opsional.
     *
     * @return int jumlah order sejudul yang benar-benar berpindah (0 = tak ada perubahan)
     */
    public function advance(TitleProgress $progress, User $actor, ?string $note = null): int
    {
        $this->requirePermission($actor, 'naskah.advance');
        $this->requireBidang($actor, $progress->bidang);

        $next = $progress->nextStage();
        if ($next === null) {
            throw ValidationException::withMessages(['status' => 'Naskah sudah berada di tahap akhir.']);
        }

        $this->assertLayoutUnlocked($progress, $next);
        $this->assertLinkTerbit($progress, $next);
        $this->assertPutaranTerjawab($progress);

        return $this->applyGroup($progress, $next, $actor, $note, false, 'status_advanced');
    }

    /**
     * Naskah tak boleh meninggalkan tahap Revisi selama masih ada putaran terbuka yang
     * sudah punya permintaan tapi belum ada jawabannya.
     *
     * Pesannya menyebut nomor putaran supaya orang tahu yang mana — "tidak bisa maju"
     * saja membuat orang menebak, lalu menyerah.
     */
    private function assertPutaranTerjawab(TitleProgress $progress): void
    {
        $title = $progress->orderDetail?->titleRef;
        if ($title === null) {
            return;
        }

        $penahan = app(ManuscriptRevisionService::class)->penahan($title, $progress->status);
        if ($penahan === null) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => "Putaran revisi ke-{$penahan->round} belum dijawab. "
                        . 'Unggah hasil revisi, atau tutup putarannya dengan catatan.',
        ]);
    }

    /**
     * Pasangan tahap yang boleh dimundurkan lewat alur normal (bukan Koreksi).
     *
     * Sengaja daftar tertutup: "mundur satu tahap dari mana saja" butuh lantai pengaman
     * di banyak tempat dan tak ada yang memintanya. Daftar ini juga yang menentukan
     * kapan tombol "Kembalikan ke ..." muncul, sehingga tombol dan aturannya tak bisa
     * berselisih — dan buku tak lagi bisa melompat ke Layout lewat tombol revisi.
     */
    public const MUNDUR_SAH = [
        'loa'     => 'revisi',
        'editing' => 'pembuatan',
    ];

    /**
     * Mengembalikan naskah satu tahap ke belakang sebagai ALUR NORMAL — dicatat dengan
     * is_correction = false supaya "Koreksi" tetap berarti "ada yang salah" dan tak
     * tercampur dengan kerja harian PJ.
     *
     * @return int jumlah order sejudul yang ikut mundur
     */
    public function kembalikan(TitleProgress $progress, string $target, User $actor, string $note): int
    {
        $this->requirePermission($actor, 'naskah.advance');
        $this->requireBidang($actor, $progress->bidang);

        if (trim($note) === '') {
            throw ValidationException::withMessages([
                'note' => 'Alasan wajib diisi saat mengembalikan naskah.',
            ]);
        }

        if ((self::MUNDUR_SAH[$progress->status] ?? null) !== $target) {
            throw ValidationException::withMessages([
                'status' => 'Naskah hanya bisa dikembalikan dari LoA ke Revisi, atau dari Editing ke Pembuatan.',
            ]);
        }

        if ($progress->archived_at !== null || $progress->cancelled_at !== null
            || TitleProgress::isFinal($progress->status)) {
            throw ValidationException::withMessages([
                'status' => 'Naskah yang sudah final, diarsipkan, atau dibatalkan hanya bisa dibuka lewat Koreksi superadmin.',
            ]);
        }

        return $this->applyGroup($progress, $target, $actor, $note, false, 'status_returned', true);
    }

    /**
     * Koreksi tahap (mundur atau lompat), termasuk membuka naskah yang sudah final.
     * Hanya superadmin — `naskah.correct` tidak dihibahkan ke role mana pun dan
     * superadmin lolos lewat Gate::before. Catatan WAJIB.
     *
     * @return int jumlah order terdampak (0 = target sama dengan tahap sekarang)
     */
    public function correct(TitleProgress $progress, string $target, User $actor, string $note): int
    {
        $this->requirePermission($actor, 'naskah.correct');

        if (! $progress->isValidStatus($target)) {
            throw ValidationException::withMessages(['status' => 'Tahap tidak valid untuk jenis naskah ini.']);
        }

        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Catatan wajib diisi untuk koreksi tahap.']);
        }

        // Submit tanpa perubahan bukan kesalahan — pemanggil menampilkan info ramah.
        if ($target === $progress->status) {
            return 0;
        }

        return $this->applyGroup($progress, $target, $actor, $note, true, 'status_corrected');
    }

    /**
     * Satu-satunya perpindahan otomatis: upload naskah masuk.
     *  - tahap `pembuatan` + diunggah pelaksananya → `editing` (bukti kerja selesai);
     *  - tahap `menunggu_proses` → langsung `editing`, melewati `pembuatan`, karena
     *    naskahnya datang dari klien (order tanpa jasa penulisan).
     *
     * @return int jumlah order terdampak (0 = tidak memicu apa pun)
     */
    public function autoAdvanceOnUpload(TitleProgress $progress, User $uploader, string $slot): int
    {
        if ($slot !== 'masuk') {
            return 0;
        }

        $memicu = match ($progress->status) {
            'pembuatan'       => (int) $progress->pelaksana_user_id === (int) $uploader->id,
            'menunggu_proses' => true,
            default           => false,
        };

        if (! $memicu) {
            return 0;
        }

        $affected = $this->applyGroup($progress, 'editing', $uploader, null, false, 'auto_advance_upload');

        if ($affected > 0) {
            app(Notifier::class)->naskahAutoAdvanced($progress->fresh(), $uploader);
        }

        return $affected;
    }

    /**
     * Selesaikan tahap satu bab (buku kolaborasi). Bab punya alurnya sendiri
     * (Menunggu → Pembuatan → Editing → Selesai); status buku menyusul lewat roll-up.
     *
     * @return string tahap baru bab
     */
    public function advanceChapter(ChapterProgress $chapter, User $actor, ?string $note = null): string
    {
        $this->requirePermission($actor, 'naskah.advance');
        $this->requireBidang($actor, 'buku');

        $next = $chapter->nextStage();
        if ($next === null) {
            throw ValidationException::withMessages(['status' => 'Bab ini sudah Selesai.']);
        }

        $this->applyChapterStatus($chapter, $next, $actor, $note, 'status_advanced');

        return $next;
    }

    /**
     * Bab maju otomatis saat naskahnya masuk. Dua pemicu:
     *  - tahap `pembuatan` + diunggah pelaksananya → `editing` (bukti kerja selesai);
     *  - tahap `menunggu` + bab bernaskah mandiri → `editing`, pengunggah siapa pun,
     *    karena naskahnya datang dari author dan bab itu tak akan pernah punya
     *    pelaksana. Sejalan dengan cabang `menunggu_proses` di tingkat judul.
     */
    public function autoAdvanceChapterOnUpload(ChapterProgress $chapter, User $uploader, string $slot): bool
    {
        if ($slot !== 'masuk') {
            return false;
        }

        if ($chapter->status === 'menunggu' && $chapter->naskahDariAuthor()) {
            $this->applyChapterStatus($chapter, 'editing', $uploader, null, 'auto_advance_upload');

            return true;
        }

        if ($chapter->status !== 'pembuatan') {
            return false;
        }

        if ((int) $chapter->pelaksana_user_id !== (int) $uploader->id) {
            return false;
        }

        $this->applyChapterStatus($chapter, 'editing', $uploader, null, 'auto_advance_upload');

        return true;
    }

    private function applyChapterStatus(ChapterProgress $chapter, string $target, User $actor, ?string $note, string $event): void
    {
        $from = $chapter->status;

        DB::transaction(function () use ($chapter, $target, $actor, $note, $event, $from) {
            $chapter->update([
                'status'      => $target,
                'note'        => $note,
                'updated_by'  => $actor->id,
                'started_at'  => now(),
                'last_log_at' => now(),
                // SLA hanya bermakna selama bab dibuat.
                'sla_due_at'  => $target === 'pembuatan' ? $chapter->sla_due_at : null,
            ]);

            $this->logChapter($chapter, $event, $from, $target, $actor, $note);
        });

        if ($book = $chapter->chapter?->title) {
            app(ChapterRollupService::class)->recalc($book, $actor);
        }
    }

    /** Riwayat bab menempel pada TitleProgress buku — satu linimasa per naskah. */
    private function logChapter(ChapterProgress $chapter, string $event, string $from, string $to, User $actor, ?string $note): void
    {
        $progress = $chapter->chapter?->title?->orderDetails()->notWithdrawn()->with('titleProgress')->get()
            ->map->titleProgress->filter()->first();

        if (! $progress) {
            return;
        }

        $this->log(
            $progress,
            $event,
            ChapterProgress::labelFor($from),
            $chapter->chapter->judul . ': ' . ChapterProgress::labelFor($to),
            $actor,
            $note
        );
    }

    /**
     * Buku kolaborasi: Layout→Terbit berjalan level buku dan baru terbuka setelah
     * SELURUH bab Selesai. Gerbang ini yang membuat tombol "Mulai Layout" bermakna.
     */
    private function assertLayoutUnlocked(TitleProgress $progress, string $next): void
    {
        if ($next !== 'layout') {
            return;
        }

        $book = $progress->orderDetail?->titleRef;
        if (! $book) {
            return;
        }

        $rollup = app(ChapterRollupService::class);
        if ($rollup->isCollaborative($book) && ! $rollup->chaptersDone($book)) {
            throw ValidationException::withMessages([
                'status' => 'Semua bab harus Selesai dulu sebelum masuk tahap Layout.',
            ]);
        }
    }

    /**
     * Tahap akhir menuntut link karya terbit.
     *
     * Naskah yang "terbit" tanpa alamat terbitnya adalah klaim tanpa bukti: ia langsung
     * dihitung selesai, menutup ordernya, dan memenuhi syarat arsip. Gerbang ini menahan
     * ketiganya sekaligus — archiveEligible() sudah menuntut manuscriptIsFinal(), jadi
     * tak perlu gerbang kedua di sisi arsip yang bisa berbeda pendapat.
     *
     * correct() TIDAK melewati gerbang ini: koreksi adalah wewenang superadmin untuk
     * membetulkan keadaan, termasuk naskah lama yang linknya memang tak pernah tercatat.
     */
    private function assertLinkTerbit(TitleProgress $progress, string $next): void
    {
        if (! TitleProgress::isFinal($next)) {
            return;
        }

        $title = $progress->orderDetail?->titleRef;
        if ($title === null) {
            return; // detail tanpa Title — tak ada klaim terbit yang bisa diperiksa
        }

        if (! $title->butuhLinkTerbit()) {
            return;
        }

        throw ValidationException::withMessages([
            'link_terbit' => $title->jenis === 'buku'
                ? 'Isi dulu Link Buku Terbit sebelum menandai naskah Terbit.'
                : 'Isi dulu Link Artikel Terbit sebelum menandai naskah Publish.',
        ]);
    }

    /**
     * Terapkan tahap ke SELURUH order sejudul dalam satu transaksi.
     * Maju: hanya order yang masih tertinggal di belakang target ikut bergerak.
     * Koreksi: seluruh order disinkronkan ke target, termasuk yang sudah di depan.
     */
    private function applyGroup(
        TitleProgress $progress,
        string $target,
        User $actor,
        ?string $note,
        bool $isCorrection,
        string $event,
        bool $bolehMundur = false
    ): int {
        $group     = $this->groupOf($progress);
        $stages    = $progress->getStages();
        $targetIdx = array_search($target, $stages, true);
        $changed   = [];

        DB::transaction(function () use ($group, $stages, $target, $targetIdx, $actor, $note, $isCorrection, $event, $bolehMundur, &$changed) {
            foreach ($group as $p) {
                $idx = array_search($p->status, $stages, true);
                if ($p->status === $target) {
                    continue;
                }
                // Penjaga ini dulu mencampur dua pertanyaan: "apakah ini koreksi" dan
                // "bolehkah bergerak mundur". Untuk perpindahan mundur SETIAP anggota
                // punya $idx > $targetIdx, jadi tanpa $bolehMundur seluruh grup dilewati
                // dan fungsinya mengembalikan 0 tanpa suara — gagal senyap.
                if (! $isCorrection && ! $bolehMundur && $idx !== false && $idx > $targetIdx) {
                    continue; // sudah lebih maju dari target
                }

                $from = $p->status;
                $this->applyStatus($p, $from, $target, $actor, $note, $isCorrection, $event);
                $this->syncArchiveFlag($p, $target, $actor);
                $changed[] = [$p, $from];
            }
        });

        $notifier = app(Notifier::class);
        foreach ($changed as [$p, $from]) {
            $notifier->naskahTahapBerubah($p, $actor, $from, $target, $isCorrection);

            // Publish/terbit: marketing pemilik TIAP order yang mengabari kliennya.
            if (TitleProgress::isFinal($target)) {
                $notifier->naskahPublished($p->fresh(), $actor);
            }
        }

        // Putaran di tahap yang baru saja DITINGGALKAN ditutup wajar — close_note
        // dibiarkan null, dan kosong-atau-tidak itulah yang membedakannya dari
        // penutupan paksa lewat pintu darurat.
        if ($changed !== []) {
            [$pertama, $dari] = $changed[0];
            $title = $pertama->orderDetail?->titleRef;
            if ($title && in_array($dari, ManuscriptRevision::STAGES, true)) {
                app(ManuscriptRevisionService::class)->tutupOtomatis($title, $dari, $actor);
            }
        }

        return count($changed);
    }

    /**
     * Naskah selesai pindah ke Arsip, tidak lenyap dari sistem. Koreksi mundur oleh
     * superadmin mengembalikannya ke papan.
     */
    private function syncArchiveFlag(TitleProgress $progress, string $target, User $actor): void
    {
        if (TitleProgress::isFinal($target)) {
            if ($progress->archived_at === null) {
                $progress->update(['archived_at' => now()]);
                $this->log($progress, 'diarsipkan', TitleProgress::labelFor($target), 'Arsip', $actor);
            }

            return;
        }

        if ($progress->archived_at !== null) {
            $progress->update(['archived_at' => null]);
        }
    }

    /** @return Collection<int,TitleProgress> */
    private function groupOf(TitleProgress $progress): Collection
    {
        $key = $progress->orderDetail?->group_key;
        if ($key === null) {
            return collect([$progress]);
        }

        // `orderDetail.order` ikut dimuat: applyStatus() menyinkronkan order tiap
        // anggota grup, dan tanpa ini setiap anggota menembak SELECT-nya sendiri di
        // dalam transaksi.
        return TitleProgress::with('orderDetail.order')
            ->whereNull('withdrawn_at')
            ->whereHas('orderDetail', fn ($q) => $q->where('group_key', $key))
            ->get();
    }

    /**
     * Tulis perubahan status + log untuk satu progress (tanpa validasi — pemanggil
     * yang memvalidasi). `$event` boleh ditimpa untuk perpindahan bernama khusus
     * (mis. `auto_advance_upload`) supaya riwayat menyebut sebabnya, bukan sekadar
     * "maju tahap".
     */
    private function applyStatus(TitleProgress $progress, string $from, string $target, User $actor, ?string $note, bool $isCorrection, ?string $event = null): TitleProgress
    {
        $progress->update([
            'status'        => $target,
            'assigned_role' => TitleProgress::getHandlerForStatus($target),
            'note'          => $note,
            'updated_by'    => $actor->id,
            'started_at'    => now(),
        ]);

        $this->log(
            $progress,
            $event ?? ($isCorrection ? 'status_corrected' : 'status_advanced'),
            Str::title(str_replace('_', ' ', $from)),
            Str::title(str_replace('_', ' ', $target)),
            $actor,
            $note,
            $isCorrection
        );

        app(OrderFulfillmentService::class)->syncFromProgress($progress);

        return $progress;
    }

    /** Catat satu entri aktivitas + perbarui penanda aktivitas terbaru pada progress. */
    private function log(TitleProgress $progress, string $event, ?string $from, ?string $to, User $actor, ?string $note = null, bool $isCorrection = false): void
    {
        TitleProgressLog::create([
            'title_progress_id' => $progress->id,
            'event'             => $event,
            'from_value'        => $from,
            'to_value'          => $to,
            'changed_by'        => $actor->id,
            'note'              => $note,
            'is_correction'     => $isCorrection,
        ]);

        $progress->update(['last_log_at' => now()]);
    }

    public function setPriority(TitleProgress $progress, string $priority, User $actor): TitleProgress
    {
        if (!$actor->hasAnyRole(['production', 'manager', 'superadmin', 'admin'])) {
            throw new AuthorizationException();
        }
        if (!in_array($priority, TitleProgress::PRIORITIES, true)) {
            throw ValidationException::withMessages(['priority' => 'Prioritas tidak valid.']);
        }

        if ($progress->priority !== $priority) {
            $from = ucfirst((string) $progress->priority);
            $progress->update(['priority' => $priority]);
            $this->log($progress, 'priority_changed', $from, ucfirst($priority), $actor);
        }

        return $progress;
    }

    /** Set/clear target tanggal terbit/publish. Kosong = hapus target. Marketing juga boleh. */
    public function setTargetDate(TitleProgress $progress, ?string $date, User $actor): TitleProgress
    {
        if (! $actor->hasAnyRole(['marketing', 'production', 'manager', 'superadmin', 'admin'])) {
            throw new AuthorizationException();
        }

        $value = null;
        if ($date !== null && trim($date) !== '') {
            try {
                $value = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', trim($date))->startOfDay();
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['target_date' => 'Tanggal target tidak valid.']);
            }
        }

        $oldDate = optional($progress->target_date)->toDateString();
        $newDate = optional($value)->toDateString();
        if ($oldDate !== $newDate) {
            $progress->update(['target_date' => $value]);
            $this->log($progress, 'target_set', $oldDate ?? '—', $newDate ?? '—', $actor);
        }

        return $progress;
    }

    /**
     * Buat TitleProgress untuk detail baru. Jika sudah ada order lain berjudul sama (grup)
     * yang sedang diproses, warisi status + penugasan grup agar tetap sinkron; jika tidak,
     * mulai dari 'menunggu_proses'.
     *
     * MEWARISI STATUS FINAL SENGAJA MELEWATI assertLinkTerbit(). Order baru pada judul
     * yang sudah terbit memang pekerjaan yang sudah selesai; menurunkannya ke
     * 'menunggu_proses'/'proofreading' cuma demi lolos gerbang akan salah menggambarkan
     * keadaan lebih parah daripada link yang hilang. Jadi jangan tambahkan cadangan di
     * sini.
     *
     * Asumsinya: link judul TIDAK dikosongkan setelah judulnya terbit. Asumsi itu bisa
     * dilanggar — `JournalSubmission.link_publish` boleh dikosongkan/dihapus lewat
     * Direktori Jurnal, dan BookIsbn.link_terbit ikut lepas kalau status ISBN diturunkan
     * di bawah 'cetak'. Bila itu terjadi lalu order baru masuk, order itu lahir final,
     * lalu OrderFulfillmentService menstempelnya 'selesai' + completed_at tanpa alamat
     * terbit sama sekali.
     *
     * Anomalinya ada di SISI TULIS, bukan di sini: mengosongkan link pada judul yang
     * sudah terbit itulah yang harus dijaga (updateInfo pada halaman judul, dan
     * JournalSubmissionController). Perbaiki di sana, jangan di jalur warisan ini.
     */
    public function createForDetail(OrderDetail $detail, ?int $actorId = null): TitleProgress
    {
        $bookTypes = ['bk_mandiri', 'bk_kolab'];
        $stages = in_array($detail->type, $bookTypes, true)
            ? TitleProgress::BOOK_STAGES
            : TitleProgress::ARTICLE_STAGES;

        // Bab yang dijual ulang setelah penulis sebelumnya mundur harus mulai bersih:
        // jangan warisi tahap maupun pelaksana dari order yang sudah ditarik.
        $sibling = OrderDetail::where('group_key', $detail->group_key)
            ->where('id', '!=', $detail->id)
            ->notWithdrawn()
            ->whereHas('titleProgress')
            ->with('titleProgress')
            ->get()
            ->map->titleProgress
            ->sortBy(fn ($p) => array_search($p->status, $stages, true))
            ->first();

        $status = $sibling->status ?? 'menunggu_proses';

        $progress = TitleProgress::create([
            'order_detail_id'  => $detail->id,
            'status'           => $status,
            'assigned_role'    => TitleProgress::getHandlerForStatus($status),
            'pelaksana_user_id' => $sibling->pelaksana_user_id ?? null,
            'pj_user_id'       => $sibling->pj_user_id ?? null,
            'bidang'           => in_array($detail->type, $bookTypes, true) ? 'buku' : 'artikel',
            'priority'         => $sibling->priority ?? 'normal',
            'updated_by'       => $actorId,
            'started_at'       => now(),
        ]);

        // Buku: pastikan bab + progress bab (roll-up). Artikel: tak ada bab.
        if (in_array($detail->type, $bookTypes, true) && $detail->title_id) {
            app(\App\Services\ChapterManuscriptService::class)->ensureChapters($detail->titleRef);
        }

        // Status warisan grup bisa langsung final (semua order sejudul sudah terbit).
        // Tanpa ini order barunya lahir `berjalan` dan tak akan pernah ada perpindahan
        // tahap yang menutupnya.
        app(OrderFulfillmentService::class)->syncFromProgress($progress);

        return $progress;
    }

    // ─── Aksi serempak untuk satu grup judul (semua order judul yang sama) ───

    public function setGroupPriority(iterable $progresses, string $priority, User $actor): void
    {
        DB::transaction(function () use ($progresses, $priority, $actor) {
            foreach (collect($progresses) as $p) {
                $this->setPriority($p, $priority, $actor);
            }
        });
    }

    public function setGroupTargetDate(iterable $progresses, ?string $date, User $actor): void
    {
        DB::transaction(function () use ($progresses, $date, $actor) {
            foreach (collect($progresses) as $p) {
                $this->setTargetDate($p, $date, $actor);
            }
        });
    }

    // CATATAN: clearLogs() dihapus 2026-08-10 bersama modul distribusi lama. Blueprint
    // wireframe menyatakan "Hapus riwayat — tidak ada yang boleh", termasuk superadmin;
    // audit total baru bermakna kalau memang tak ada jalurnya di kode.
}
