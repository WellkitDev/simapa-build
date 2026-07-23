<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ChapterProgress;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\ChapterManuscriptService;
use App\Services\ManuscriptFileService;
use App\Services\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BookDistributionController extends Controller
{
    public function __construct(private ChapterManuscriptService $chapters) {}

    public function index()
    {
        $titles = Title::where('jenis', 'buku')
            ->whereHas('orderDetails')
            ->with(['orderDetails.titleProgress'])
            ->orderBy('title')->get();

        return view('distribusi.buku.index', compact('titles'));
    }

    public function show(int $id)
    {
        $title = Title::where('jenis', 'buku')->findOrFail($id);
        $this->chapters->ensureChapters($title);
        $title->load(['chapters.progress.assignedUser', 'chapters.authors', 'orderDetails.order.user']);

        $editors = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['production', 'manager', 'admin']))
            ->orderBy('name')->get(['id', 'name']);

        $filesFor = function (?int $chapterId) use ($title) {
            $svc = app(ManuscriptFileService::class);
            return ['masuk' => $svc->versions($title, $chapterId, 'masuk'), 'final' => $svc->versions($title, $chapterId, 'final')];
        };

        return view('distribusi.buku.show', compact('title', 'editors', 'filesFor'));
    }

    public function assignEditorAll(Request $request, int $id)
    {
        $title = Title::where('jenis', 'buku')->findOrFail($id);
        $raw = $request->input('assigned_user_id');
        $userId = ($raw === null || $raw === '') ? null : (int) $raw;

        return $this->run($request, function () use ($title, $userId, $request) {
            $this->chapters->assignEditorAll($title, $userId, $request->user());
            $this->notify($title, $request->user(), 'Editor semua bab diperbarui');
        }, 'Editor semua bab diperbarui.');
    }

    public function assignChapterEditor(Request $request, int $cp)
    {
        $progress = ChapterProgress::findOrFail($cp);
        $raw = $request->input('assigned_user_id');
        $userId = ($raw === null || $raw === '') ? null : (int) $raw;

        return $this->run($request, function () use ($progress, $userId, $request) {
            $this->chapters->assignEditor($progress, $userId, $request->user());
            $this->notifyChapter($progress, $request->user(), 'Editor bab diperbarui');
        }, 'Editor bab diperbarui.');
    }

    public function moveChapter(Request $request, int $cp)
    {
        $progress = ChapterProgress::findOrFail($cp);

        return $this->run($request, function () use ($progress, $request) {
            $this->chapters->changeStatus($progress, (string) $request->input('status'), $request->user(), $request->input('note'));
            $this->notifyChapter($progress, $request->user(), 'Tahap bab diperbarui');
        }, 'Tahap bab diperbarui.');
    }

    public function setPriority(Request $request, int $id, \App\Services\TitleProgressService $svc)
    {
        $title = Title::where('jenis', 'buku')->findOrFail($id);
        $progresses = $this->titleProgresses($title);

        return $this->run($request, function () use ($svc, $progresses, $request) {
            $svc->setGroupPriority($progresses, (string) $request->input('priority'), $request->user());
        }, 'Prioritas diperbarui.');
    }

    public function setTarget(Request $request, int $id, \App\Services\TitleProgressService $svc)
    {
        $title = Title::where('jenis', 'buku')->findOrFail($id);
        $progresses = $this->titleProgresses($title);

        return $this->run($request, function () use ($svc, $progresses, $request) {
            $svc->setGroupTargetDate($progresses, $request->input('target_date'), $request->user());
        }, 'Target diperbarui.');
    }

    public function uploadFile(Request $request, int $id, ManuscriptFileService $files)
    {
        $request->validate(['slot' => 'required|in:masuk,final', 'file' => 'required|file|mimes:pdf,doc,docx,zip|max:20480']);
        $title = Title::where('jenis', 'buku')->findOrFail($id);

        return $this->run($request, function () use ($files, $title, $request) {
            $files->upload($title, null, $request->input('slot'), $request->file('file'), $request->user());
            $this->notify($title, $request->user(), 'File buku diunggah');
        }, 'File buku diunggah.');
    }

    public function uploadChapterFile(Request $request, int $cp, ManuscriptFileService $files)
    {
        $request->validate(['slot' => 'required|in:masuk,final', 'file' => 'required|file|mimes:pdf,doc,docx,zip|max:20480']);
        $progress = ChapterProgress::with('chapter.title')->findOrFail($cp);
        $chapter = $progress->chapter;

        return $this->run($request, function () use ($files, $chapter, $request, $progress) {
            $files->upload($chapter->title, $chapter, $request->input('slot'), $request->file('file'), $request->user());
            $this->notifyChapter($progress, $request->user(), 'File bab diunggah');
        }, 'File bab diunggah.');
    }

    // ── helpers ──

    private function titleProgresses(Title $title)
    {
        return TitleProgress::with('orderDetail')
            ->whereHas('orderDetail', fn ($q) => $q->where('title_id', $title->id))->get();
    }

    private function notify(Title $title, User $actor, string $summary): void
    {
        $p = $this->titleProgresses($title)->first();
        if ($p) { app(Notifier::class)->distribusiChanged($p, $actor, $summary); }
    }

    private function notifyChapter(ChapterProgress $progress, User $actor, string $summary): void
    {
        $title = $progress->chapter->title ?? null;
        if ($title) { $this->notify($title, $actor, $summary); }
    }

    private function run(Request $request, \Closure $action, string $success)
    {
        try {
            $action();
            return back()->with('success', $success);
        } catch (AuthorizationException | ValidationException $e) {
            $msg = $e instanceof ValidationException
                ? (collect($e->errors())->flatten()->first() ?? 'Data tidak valid.')
                : ($e->getMessage() ?: 'Anda tidak berhak melakukan aksi ini.');
            return back()->with('error', $msg);
        }
    }
}
