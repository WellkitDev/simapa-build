<?php
// app/Http/Controllers/Pages/ManuscriptTrackerController.php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
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
        $view = $request->query('view') === 'list' ? 'list' : 'board';

        $editorFilter = $request->query('editor');
        if ($editorFilter === 'me') {
            $editorFilter = (string) Auth::id();
        }

        $details = OrderDetail::query()
            ->with(['order.user', 'authors', 'scopes', 'titleProgress.assignedUser'])
            ->whereHas('titleProgress')
            ->when(
                $tipe === 'buku',
                fn ($q) => $q->whereIn('type', $bookTypes),
                fn ($q) => $q->whereNotIn('type', $bookTypes)
            )
            ->when($editorFilter !== null && $editorFilter !== '', fn ($q) =>
                $q->whereHas('titleProgress', fn ($t) => $t->where('assigned_user_id', $editorFilter)))
            ->when($request->filled('priority'), fn ($q) =>
                $q->whereHas('titleProgress', fn ($t) => $t->where('priority', $request->query('priority'))))
            ->get();

        $stages   = $tipe === 'buku' ? TitleProgress::BOOK_STAGES : TitleProgress::ARTICLE_STAGES;
        $byStatus = $details->groupBy(fn ($d) => $d->titleProgress->status);
        $editors  = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['production', 'manager']))
            ->orderBy('name')->get(['id', 'name']);
        $zones    = $this->buildZones($stages);

        return view('manuscript.' . $view, compact('details', 'stages', 'byStatus', 'tipe', 'view', 'editors', 'zones'));
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

        if ($redirect = $this->runOrFlash($request, fn () =>
            $service->changeStatus($progress, (string) $request->input('status'), Auth::user(), $request->input('note'))
        )) {
            return $redirect;
        }

        $progress->refresh();
        $label = Str::title(str_replace('_', ' ', $progress->status));
        if ($request->expectsJson()) {
            return response()->json([
                'ok'            => true,
                'id'            => $progress->id,
                'status'        => $progress->status,
                'assigned_role' => $progress->assigned_role,
                'message'       => "Naskah dipindahkan ke {$label}.",
            ]);
        }
        return back()->with('success', "Naskah dipindahkan ke {$label}.");
    }

    public function assign(Request $request, int $id, TitleProgressService $service)
    {
        $progress = TitleProgress::findOrFail($id);
        $raw = $request->input('assigned_user_id');
        $userId = ($raw === null || $raw === '') ? null : (int) $raw;

        if ($redirect = $this->runOrFlash($request, fn () =>
            $service->assignEditor($progress, $userId, Auth::user())
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
        $progress = TitleProgress::findOrFail($id);

        if ($redirect = $this->runOrFlash($request, fn () =>
            $service->setPriority($progress, (string) $request->input('priority'), Auth::user())
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
}
