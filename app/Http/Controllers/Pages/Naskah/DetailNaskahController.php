<?php

namespace App\Http\Controllers\Pages\Naskah;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\ChapterProgress;
use App\Models\ManuscriptFile;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\ChapterRollupService;
use App\Services\ManuscriptFileService;
use App\Services\TitleProgressService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Detail Naskah — halaman kanonik satu naskah (Layar 3 / 3B).
 *
 * Parameter {id} SELALU `order_detail_id`: identitas utama modul ini adalah kode order,
 * dan satu halaman mewakili satu order meski aksinya berlaku serempak untuk seluruh
 * order sejudul (banner grup).
 *
 * Controller tidak memutuskan aturan apa pun — semua gerbang ada di service. Yang
 * dirakit di sini hanyalah flag izin untuk view, supaya Blade tak perlu memeriksa role.
 */
class DetailNaskahController extends Controller
{
    public function show(Request $request, int $id)
    {
        $progress = $this->progress($id);
        $actor    = $request->user();

        $progress->loadMissing([
            'orderDetail.order.user', 'orderDetail.authors', 'orderDetail.titleRef',
            'pj', 'pelaksana', 'logs.changedBy',
        ]);

        $book     = $progress->orderDetail->titleRef;
        $rollup   = app(ChapterRollupService::class);
        $isKolab  = $book !== null && $rollup->isCollaborative($book);

        return view('naskah.detail', [
            'progress'   => $progress,
            'grup'       => $this->group($progress),
            'stages'     => $progress->getStages(),
            'next'       => $progress->nextStage(),
            'berkas'     => $this->files($progress),
            'isKolab'    => $isKolab,
            'bab'        => $isKolab ? $book->chapters()->with(['progress.pelaksana', 'authors'])->orderBy('urutan')->get() : collect(),
            'ringkasan'  => $isKolab ? $rollup->summary($book) : null,
            'pelaksanaOptions' => $this->usersWithRole('production'),
            'adminOptions'     => $this->usersWithRole('admin'),
            'izin'       => $this->permissions($actor),
        ]);
    }

    // ─── Aksi level naskah ───

    public function selesaikan(Request $request, int $id, TitleProgressService $stages)
    {
        $progress = $this->progress($id);

        return $this->run($request, function () use ($stages, $progress, $request) {
            $n = $stages->advance($progress, $request->user(), $request->input('note'));

            return $n > 1
                ? "Tahap diperbarui — berlaku untuk {$n} order sejudul."
                : 'Tahap diperbarui.';
        });
    }

    /** "Perlu Revisi": maju khusus dari Editing ke Revisi dengan alasan baku wajib. */
    public function revisi(Request $request, int $id, TitleProgressService $stages)
    {
        $request->validate(['alasan' => 'required|string|max:255']);
        $progress = $this->progress($id);

        return $this->run($request, function () use ($stages, $progress, $request) {
            $stages->advance($progress, $request->user(), 'Perlu revisi: ' . $request->input('alasan'));

            return 'Naskah ditandai perlu revisi.';
        });
    }

    public function koreksi(Request $request, int $id, TitleProgressService $stages)
    {
        $progress = $this->progress($id);

        return $this->run($request, function () use ($stages, $progress, $request) {
            $n = $stages->correct(
                $progress,
                (string) $request->input('status'),
                $request->user(),
                (string) $request->input('note')
            );

            return $n === 0 ? 'Tidak ada perubahan.' : 'Tahap dikoreksi.';
        });
    }

    public function distribusi(Request $request, int $id, AssignmentService $svc)
    {
        $request->validate(['pelaksana_user_id' => 'required|integer']);
        $progress = $this->progress($id);

        return $this->run($request, function () use ($svc, $progress, $request) {
            $svc->distribute($progress, (int) $request->input('pelaksana_user_id'), $request->user());

            return 'Tugas didistribusikan ke pelaksana.';
        });
    }

    public function claim(Request $request, int $id, AssignmentService $svc)
    {
        $progress = $this->progress($id);

        return $this->run($request, function () use ($svc, $progress, $request) {
            $svc->claim($progress, $request->user());

            return 'Tugas berhasil diambil.';
        });
    }

    public function tarik(Request $request, int $id, AssignmentService $svc)
    {
        $progress = $this->progress($id);

        return $this->run($request, function () use ($svc, $progress, $request) {
            $svc->withdraw($progress, $request->user());

            return 'Tugas ditarik dari pelaksana.';
        });
    }

    public function operPj(Request $request, int $id, AssignmentService $svc)
    {
        $request->validate(['pj_user_id' => 'required|integer']);
        $progress = $this->progress($id);

        return $this->run($request, function () use ($svc, $progress, $request) {
            $svc->transferPj($progress, (int) $request->input('pj_user_id'), $request->user());

            return 'Penanggung jawab dioper.';
        });
    }

    public function prioritas(Request $request, int $id, TitleProgressService $stages)
    {
        $progress = $this->progress($id);

        return $this->run($request, function () use ($stages, $progress, $request) {
            $stages->setGroupPriority($this->group($progress), (string) $request->input('priority'), $request->user());

            return 'Prioritas diperbarui.';
        });
    }

    public function target(Request $request, int $id, TitleProgressService $stages)
    {
        $progress = $this->progress($id);

        return $this->run($request, function () use ($stages, $progress, $request) {
            $stages->setGroupTargetDate($this->group($progress), $request->input('target_date'), $request->user());

            return 'Target diperbarui.';
        });
    }

    public function hold(Request $request, int $id, AssignmentService $svc)
    {
        $progress = $this->progress($id);

        return $this->run($request, function () use ($svc, $progress, $request) {
            $alasan = $request->input('alasan');

            if ($progress->is_on_hold) {
                $svc->unhold($progress, $request->user(), $alasan);

                return 'Naskah dilanjutkan kembali.';
            }

            $svc->hold($progress, $request->user(), $alasan);

            return 'Naskah ditahan sementara.';
        });
    }

    public function batal(Request $request, int $id, AssignmentService $svc)
    {
        $progress = $this->progress($id);

        return $this->run($request, function () use ($svc, $progress, $request) {
            $svc->cancel($progress, $request->user(), (string) $request->input('cancel_reason'));

            return 'Naskah dibatalkan.';
        });
    }

    public function file(Request $request, int $id, ManuscriptFileService $files)
    {
        $request->validate([
            'slot' => 'required|in:' . implode(',', array_keys(ManuscriptFile::SLOTS)),
            'file' => 'required|file|mimes:pdf,doc,docx,zip|max:20480',
        ]);
        $progress = $this->progress($id);
        $title    = $progress->orderDetail->titleRef;

        if (! $title) {
            return back()->with('error', 'Naskah ini belum tertaut ke judul.');
        }

        return $this->run($request, function () use ($files, $title, $request) {
            $files->upload($title, null, $request->input('slot'), $request->file('file'), $request->user());

            return 'File naskah diunggah.';
        });
    }

    // ─── Aksi level bab ───

    public function babDistribusi(Request $request, int $cp, AssignmentService $svc)
    {
        $request->validate(['pelaksana_user_id' => 'required|integer']);
        $chapter = ChapterProgress::findOrFail($cp);

        return $this->run($request, function () use ($svc, $chapter, $request) {
            $svc->distribute($chapter, (int) $request->input('pelaksana_user_id'), $request->user());

            return 'Bab didistribusikan ke pelaksana.';
        });
    }

    public function babClaim(Request $request, int $cp, AssignmentService $svc)
    {
        $chapter = ChapterProgress::findOrFail($cp);

        return $this->run($request, function () use ($svc, $chapter, $request) {
            $svc->claim($chapter, $request->user());

            return 'Bab berhasil diambil.';
        });
    }

    public function babSelesaikan(Request $request, int $cp, TitleProgressService $stages)
    {
        $chapter = ChapterProgress::findOrFail($cp);

        return $this->run($request, function () use ($stages, $chapter, $request) {
            $baru = $stages->advanceChapter($chapter, $request->user(), $request->input('note'));

            return 'Bab diperbarui ke tahap ' . ChapterProgress::labelFor($baru) . '.';
        });
    }

    public function babFile(Request $request, int $cp, ManuscriptFileService $files)
    {
        $request->validate([
            'slot' => 'required|in:' . implode(',', array_keys(ManuscriptFile::SLOTS)),
            'file' => 'required|file|mimes:pdf,doc,docx,zip|max:20480',
        ]);
        $chapter = ChapterProgress::with('chapter.title')->findOrFail($cp);

        return $this->run($request, function () use ($files, $chapter, $request) {
            $files->upload(
                $chapter->chapter->title,
                $chapter->chapter,
                $request->input('slot'),
                $request->file('file'),
                $request->user()
            );

            return 'File naskah bab diunggah.';
        });
    }

    /** Pemetaan bab→author: wajib sebelum bab bisa didistribusikan. */
    public function babAuthor(Request $request, int $cp)
    {
        $request->validate(['author' => 'required|string|max:255']);
        $chapter = ChapterProgress::with('chapter')->findOrFail($cp);

        return $this->run($request, function () use ($chapter, $request) {
            $nama   = trim((string) $request->input('author'));
            $author = is_numeric($nama)
                ? Author::findOrFail((int) $nama)
                : Author::firstOrCreate(['name' => $nama]);

            $chapter->chapter->authors()->syncWithoutDetaching([
                $author->id => ['position' => $chapter->chapter->authors()->count() + 1],
            ]);

            return 'Author bab dipetakan.';
        });
    }

    // ─── Helper ───

    private function progress(int $orderDetailId): TitleProgress
    {
        return TitleProgress::with('orderDetail')
            ->where('order_detail_id', $orderDetailId)
            ->firstOrFail();
    }

    /** Seluruh order sejudul — dasar banner "berlaku untuk N order". */
    private function group(TitleProgress $progress)
    {
        $key = $progress->orderDetail?->group_key;
        if ($key === null) {
            return collect([$progress]);
        }

        return TitleProgress::with('orderDetail.order.user')
            ->whereHas('orderDetail', fn ($q) => $q->where('group_key', $key))
            ->get();
    }

    /** Versi file per slot, level judul. */
    private function files(TitleProgress $progress): array
    {
        $title = $progress->orderDetail->titleRef;
        if (! $title) {
            return [];
        }

        $svc = app(ManuscriptFileService::class);
        $out = [];
        foreach (array_keys(ManuscriptFile::SLOTS) as $slot) {
            $out[$slot] = $svc->versions($title, null, $slot);
        }

        return $out;
    }

    private function usersWithRole(string $role)
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', $role))
            ->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Flag izin dirakit di sini supaya view tidak pernah memeriksa role sendiri —
     * satu sumber kebenaran, dan blok aksi otomatis hilang untuk marketing.
     */
    private function permissions(User $actor): array
    {
        return [
            'assign'   => $actor->can('naskah.assign'),
            'advance'  => $actor->can('naskah.advance'),
            'correct'  => $actor->can('naskah.correct'),
            'priority' => $actor->can('naskah.priority'),
            'hold'     => $actor->can('naskah.hold'),
            'cancel'   => $actor->can('naskah.cancel'),
            'target'   => $actor->can('naskah.target'),
            'upload'   => $actor->can('naskah.upload'),
            'claim'    => $actor->can('naskah.claim'),
            'author'   => $actor->can('naskah.author'),
        ];
    }

    /**
     * Semua aksi memakai pola yang sama: jalankan, ambil pesan sukses dari closure,
     * dan ubah pelanggaran aturan jadi flash Bahasa Indonesia — bukan halaman error.
     */
    private function run(Request $request, \Closure $action)
    {
        try {
            $pesan = $action();

            return back()->with('success', $pesan);
        } catch (AuthorizationException | ValidationException $e) {
            $msg = $e instanceof ValidationException
                ? (collect($e->errors())->flatten()->first() ?? 'Data tidak valid.')
                : ($e->getMessage() ?: 'Anda tidak berhak melakukan aksi ini.');

            return back()->with('error', $msg);
        }
    }
}
