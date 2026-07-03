<?php
// app/Http/Controllers/Pages/ManuscriptTrackerController.php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Models\User;
use App\Services\TitleProgressService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManuscriptTrackerController extends Controller
{
    /**
     * Jalankan aksi service. Untuk request non-JSON, ubah kegagalan otorisasi/validasi
     * menjadi redirect-back + flash error. Request AJAX tetap menerima 403/422 JSON.
     */
    private function runOrFlash(Request $request, \Closure $action): ?RedirectResponse
    {
        try {
            $action();
            return null;
        } catch (AuthorizationException | ValidationException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }
            $message = $e instanceof ValidationException
                ? (collect($e->errors())->flatten()->first() ?? 'Data tidak valid.')
                : ($e->getMessage() ?: 'Anda tidak berhak melakukan aksi ini.');
            return back()->with('error', $message);
        }
    }

    public function index(Request $request)
    {
        $bookTypes = ['bk_mandiri', 'bk_kolab'];
        $tipe = $request->query('tipe') === 'artikel' ? 'artikel' : 'buku';
        $view = in_array($request->query('view'), ['list', 'log'], true) ? $request->query('view') : 'board';

        $isProductionOnly = Auth::user()->hasRole('production') && ! Auth::user()->hasAnyRole(['manager', 'superadmin']);
        $scope = $request->query('scope') === 'all' ? 'all'
               : ($request->query('scope') === 'mine' ? 'mine' : ($isProductionOnly ? 'mine' : 'all'));
        $prodStages = TitleProgress::productionStages();

        $editorFilter = $request->query('editor');
        if ($editorFilter === 'me') {
            $editorFilter = (string) Auth::id();
        }

        $typeFilter = fn ($q) => $tipe === 'buku'
            ? $q->whereIn('type', $bookTypes)
            : $q->whereNotIn('type', $bookTypes);

        $details = OrderDetail::query()
            ->with(['order.user', 'authors', 'scopes', 'titleProgress.assignedUser', 'titleProgress.updatedBy', 'titleRef.chapters.progress.assignedUser', 'titleRef.chapters.authors'])
            ->whereHas('titleProgress')
            ->whereHas('titleProgress', function ($t) {
                $t->where(function ($q) {
                    $q->whereNotIn('status', TitleProgress::FINAL_STAGES)
                      ->orWhere('started_at', '>=', now()->subDays(TitleProgress::BOARD_RETENTION_DAYS));
                });
            })
            ->where($typeFilter)
            ->when($editorFilter !== null && $editorFilter !== '', fn ($q) =>
                $q->whereHas('titleProgress', fn ($t) => $t->where('assigned_user_id', $editorFilter)))
            ->when($request->filled('priority'), fn ($q) =>
                $q->whereHas('titleProgress', fn ($t) => $t->where('priority', $request->query('priority'))))
            ->when($request->boolean('review'), fn ($q) =>
                $q->whereHas('titleProgress', fn ($t) => $t->where('needs_review', true)))
            ->when($scope === 'mine', fn ($q) =>
                $q->whereHas('titleProgress', fn ($t) =>
                    $t->where('assigned_user_id', Auth::id())
                      ->orWhere(fn ($w) => $w->whereNull('assigned_user_id')->whereIn('status', $prodStages))))
            ->get();

        $stages   = $tipe === 'buku' ? TitleProgress::BOOK_STAGES : TitleProgress::ARTICLE_STAGES;
        $groups   = $this->buildGroupCards($details, $stages);
        if ($tipe === 'buku') {
            $svc = app(\App\Services\ChapterManuscriptService::class);
            foreach ($groups as $g) {
                if ($g->titleRef) {
                    $svc->ensureChapters($g->titleRef);
                }
            }
            $groups->each(fn ($g) => optional($g->titleRef)->load(['chapters.progress.assignedUser', 'chapters.authors']));
        }
        $prioRank = ['high' => 0, 'normal' => 1, 'low' => 2];
        $groups   = $groups->sortBy(function ($g) use ($prioRank) {
            $p = $g->titleProgress;
            $overdue = $p->target_date && $p->target_date->lt(today()) && ! TitleProgress::isFinal($p->status) ? 0 : 1;
            $target  = optional($p->target_date)->timestamp ?? PHP_INT_MAX;
            return [$overdue, $prioRank[$p->priority] ?? 1, $target];
        })->values();
        $byStatus = $groups->groupBy(fn ($g) => optional($g->titleProgress)->status ?? 'menunggu_proses');
        $editors  = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['production', 'manager']))
            ->orderBy('name')->get(['id', 'name']);
        $zones    = $this->buildZones($stages);

        // Jumlah judul yang perlu ditinjau (untuk tipe terpilih) — penanda bagi manager/superadmin.
        $reviewCount = OrderDetail::query()
            ->where($typeFilter)
            ->whereHas('titleProgress', fn ($t) => $t->where('needs_review', true))
            ->distinct('group_key')
            ->count('group_key');

        // View "Log": daftar aktivitas seluruh naskah pada tipe terpilih (DataTable).
        $logs = null;
        if ($view === 'log') {
            $logs = TitleProgressLog::query()
                ->with(['changedBy', 'titleProgress.orderDetail.order'])
                ->whereHas('titleProgress.orderDetail', $typeFilter)
                ->orderByDesc('created_at')
                ->limit(1000)
                ->get();
        }

        return view('manuscript.' . $view, compact('groups', 'stages', 'byStatus', 'tipe', 'view', 'editors', 'zones', 'reviewCount', 'logs', 'scope'));
    }

    /**
     * Satu kartu per grup judul. Representasi kartu = varian "bottleneck" (stage paling
     * awal) agar kartu duduk di kolom status grup. Lampirkan metadata grup ke representasi.
     */
    private function buildGroupCards($details, array $stages)
    {
        return collect($details)
            ->groupBy('group_key')
            ->map(function ($variants) use ($stages) {
                $repr = $variants
                    ->sortBy(fn ($d) => array_search(optional($d->titleProgress)->status ?? 'menunggu_proses', $stages, true))
                    ->first();

                $statuses = $variants->map(fn ($d) => optional($d->titleProgress)->status ?? 'menunggu_proses');

                $repr->group_order_count  = $variants->count();
                $repr->group_author_count = $variants->flatMap(fn ($d) => $d->authors->pluck('id'))->unique()->count();
                $repr->group_is_mixed     = $statuses->unique()->count() > 1;

                return $repr;
            })
            ->values();
    }

    /** Semua TitleProgress dari order yang berbagi judul (grup) dengan $progress. */
    private function groupFor(TitleProgress $progress)
    {
        return TitleProgress::with('orderDetail')
            ->whereHas('orderDetail', fn ($q) => $q->where('group_key', $progress->orderDetail->group_key))
            ->get();
    }

    /**
     * Kelompokkan stage yang berurutan berdasarkan role penanggung jawab menjadi
     * zona berlabel (Antrian → Produksi → Finalisasi) untuk batas visual di papan.
     */
    private function buildZones(array $stages): array
    {
        $meta = [
            'marketing'  => ['label' => 'Antrian',    'sub' => 'Marketing',     'tint' => '#F1F5F9', 'accent' => '#64748B'],
            'production' => ['label' => 'Produksi',   'sub' => 'Tim Produksi',  'tint' => '#EEF2FF', 'accent' => '#4C5FD5'],
            'superadmin' => ['label' => 'Finalisasi', 'sub' => 'Superadmin',    'tint' => '#ECFDF5', 'accent' => '#16A34A'],
        ];

        $zones = [];
        foreach ($stages as $stage) {
            $role = TitleProgress::getHandlerForStatus($stage);
            $last = count($zones) - 1;

            if ($last < 0 || $zones[$last]['role'] !== $role) {
                $m = $meta[$role] ?? ['label' => ucfirst($role), 'sub' => $role, 'tint' => '#F8FAFC', 'accent' => '#94A3B8'];
                $zones[] = [
                    'role'   => $role,
                    'label'  => $m['label'],
                    'sub'    => $m['sub'],
                    'tint'   => $m['tint'],
                    'accent' => $m['accent'],
                    'stages' => [],
                ];
                $last++;
            }

            $zones[$last]['stages'][] = $stage;
        }

        return $zones;
    }

    public function move(Request $request, int $id, TitleProgressService $service)
    {
        $progress = TitleProgress::with('orderDetail')->findOrFail($id);
        $group    = $this->groupFor($progress);
        $target   = (string) $request->input('status');

        if ($redirect = $this->runOrFlash($request, fn () =>
            $service->changeGroupStatus($group, $target, Auth::user(), $request->input('note'))
        )) {
            return $redirect;
        }

        $label = Str::title(str_replace('_', ' ', $target));
        if ($request->expectsJson()) {
            return response()->json([
                'ok'      => true,
                'id'      => $progress->id,
                'status'  => $target,
                'message' => "Naskah dipindahkan ke {$label}.",
            ]);
        }
        return back()->with('success', "Naskah dipindahkan ke {$label}.");
    }

    public function assign(Request $request, int $id, TitleProgressService $service)
    {
        $progress = TitleProgress::with('orderDetail')->findOrFail($id);
        $group = $this->groupFor($progress);
        $raw = $request->input('assigned_user_id');
        $userId = ($raw === null || $raw === '') ? null : (int) $raw;

        if ($redirect = $this->runOrFlash($request, fn () =>
            $service->assignGroup($group, $userId, Auth::user())
        )) {
            return $redirect;
        }

        $progress->refresh()->load('assignedUser');

        if ($request->expectsJson()) {
            return response()->json([
                'ok'            => true,
                'id'            => $progress->id,
                'assigned_user' => $progress->assignedUser
                    ? ['id' => $progress->assignedUser->id, 'name' => $progress->assignedUser->name]
                    : null,
                'message'       => 'Editor diperbarui.',
            ]);
        }
        return back()->with('success', 'Editor diperbarui.');
    }

    public function priority(Request $request, int $id, TitleProgressService $service)
    {
        $progress = TitleProgress::with('orderDetail')->findOrFail($id);
        $group = $this->groupFor($progress);

        if ($redirect = $this->runOrFlash($request, fn () =>
            $service->setGroupPriority($group, (string) $request->input('priority'), Auth::user())
        )) {
            return $redirect;
        }

        $progress->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'ok'       => true,
                'id'       => $progress->id,
                'priority' => $progress->priority,
                'message'  => 'Prioritas diperbarui.',
            ]);
        }
        return back()->with('success', 'Prioritas diperbarui.');
    }

    public function reviewed(Request $request, int $id, TitleProgressService $service)
    {
        $progress = TitleProgress::with('orderDetail')->findOrFail($id);
        $group = $this->groupFor($progress);

        if ($redirect = $this->runOrFlash($request, fn () =>
            $service->markReviewed($group, Auth::user())
        )) {
            return $redirect;
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $progress->id, 'message' => 'Ditandai sudah ditinjau.']);
        }
        return back()->with('success', 'Ditandai sudah ditinjau.');
    }

    public function target(Request $request, int $id, TitleProgressService $service)
    {
        $progress = TitleProgress::with('orderDetail')->findOrFail($id);
        $group = $this->groupFor($progress);

        if ($redirect = $this->runOrFlash($request, fn () =>
            $service->setGroupTargetDate($group, $request->input('target_date'), Auth::user())
        )) {
            return $redirect;
        }

        $progress->refresh();
        if ($request->expectsJson()) {
            return response()->json([
                'ok'          => true,
                'id'          => $progress->id,
                'target_date' => optional($progress->target_date)->toDateString(),
                'message'     => 'Target terbit diperbarui.',
            ]);
        }
        return back()->with('success', 'Target terbit diperbarui.');
    }

    public function clearLog(Request $request, int $id, TitleProgressService $service)
    {
        $progress = TitleProgress::with('orderDetail')->findOrFail($id);
        $group = $this->groupFor($progress);

        if ($redirect = $this->runOrFlash($request, fn () =>
            $service->clearLogs($group, Auth::user())
        )) {
            return $redirect;
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $progress->id, 'message' => 'Riwayat dibersihkan.']);
        }
        return back()->with('success', 'Riwayat aktivitas dibersihkan.');
    }
}
