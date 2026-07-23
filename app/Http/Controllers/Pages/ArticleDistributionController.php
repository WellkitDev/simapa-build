<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\ManuscriptFileService;
use App\Services\Notifier;
use App\Services\TitleProgressService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ArticleDistributionController extends Controller
{
    public function index()
    {
        $titles = Title::where('jenis', 'artikel')
            ->whereHas('orderDetails')
            ->with(['orderDetails.titleProgress.assignedUser'])
            ->orderBy('title')->get();

        return view('distribusi.artikel.index', compact('titles'));
    }

    public function show(int $id)
    {
        $title = Title::where('jenis', 'artikel')
            ->with(['orderDetails.order.user', 'orderDetails.authors', 'orderDetails.titleProgress.assignedUser'])
            ->findOrFail($id);

        $progresses = $this->progresses($title);
        $editors    = $this->editors();
        $files      = $this->fileVersions($title, null);

        return view('distribusi.artikel.show', compact('title', 'progresses', 'editors', 'files'));
    }

    public function assignEditor(Request $request, int $id, TitleProgressService $svc)
    {
        $title = Title::where('jenis', 'artikel')->findOrFail($id);
        $progresses = $this->progresses($title);
        $raw = $request->input('assigned_user_id');
        $userId = ($raw === null || $raw === '') ? null : (int) $raw;

        return $this->run($request, function () use ($svc, $progresses, $userId, $request) {
            $svc->assignGroup($progresses, $userId, $request->user());
            $this->notify($progresses, $request->user(), 'Editor diperbarui');
        }, 'Editor diperbarui.');
    }

    public function moveStage(Request $request, int $id, TitleProgressService $svc)
    {
        $title = Title::where('jenis', 'artikel')->findOrFail($id);
        $progresses = $this->progresses($title);

        return $this->run($request, function () use ($svc, $progresses, $request) {
            $svc->changeGroupStatus($progresses, (string) $request->input('status'), $request->user(), $request->input('note'));
        }, 'Tahap diperbarui.');
    }

    public function setPriority(Request $request, int $id, TitleProgressService $svc)
    {
        $title = Title::where('jenis', 'artikel')->findOrFail($id);
        $progresses = $this->progresses($title);

        return $this->run($request, function () use ($svc, $progresses, $request) {
            $svc->setGroupPriority($progresses, (string) $request->input('priority'), $request->user());
            $this->notify($progresses, $request->user(), 'Prioritas diperbarui');
        }, 'Prioritas diperbarui.');
    }

    public function setTarget(Request $request, int $id, TitleProgressService $svc)
    {
        $title = Title::where('jenis', 'artikel')->findOrFail($id);
        $progresses = $this->progresses($title);

        return $this->run($request, function () use ($svc, $progresses, $request) {
            $svc->setGroupTargetDate($progresses, $request->input('target_date'), $request->user());
            $this->notify($progresses, $request->user(), 'Target diperbarui');
        }, 'Target diperbarui.');
    }

    public function uploadFile(Request $request, int $id, ManuscriptFileService $files)
    {
        $request->validate([
            'slot' => 'required|in:masuk,final',
            'file' => 'required|file|mimes:pdf,doc,docx,zip|max:20480',
        ]);
        $title = Title::where('jenis', 'artikel')->findOrFail($id);

        return $this->run($request, function () use ($files, $title, $request) {
            $files->upload($title, null, $request->input('slot'), $request->file('file'), $request->user());
            $this->notify($this->progresses($title), $request->user(), 'File naskah diunggah');
        }, 'File naskah diunggah.');
    }

    // ── helpers ──

    /** @return \Illuminate\Support\Collection<int,TitleProgress> */
    private function progresses(Title $title)
    {
        return TitleProgress::with('orderDetail')
            ->whereHas('orderDetail', fn ($q) => $q->where('title_id', $title->id))
            ->get();
    }

    private function editors()
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', ['production', 'manager', 'admin']))
            ->orderBy('name')->get(['id', 'name']);
    }

    private function fileVersions(Title $title, ?int $chapterId): array
    {
        $svc = app(ManuscriptFileService::class);
        return [
            'masuk' => $svc->versions($title, $chapterId, 'masuk'),
            'final' => $svc->versions($title, $chapterId, 'final'),
        ];
    }

    private function notify($progresses, User $actor, string $summary): void
    {
        $first = collect($progresses)->first();
        if ($first) {
            app(Notifier::class)->distribusiChanged($first, $actor, $summary);
        }
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
