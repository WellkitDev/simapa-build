<?php
// app/Http/Controllers/Pages/ManuscriptTrackerController.php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\TitleProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ManuscriptTrackerController extends Controller
{
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
        $editors  = User::role(['production', 'manager'])->orderBy('name')->get(['id', 'name']);

        return view('manuscript.' . $view, compact('details', 'stages', 'byStatus', 'tipe', 'view', 'editors'));
    }

    public function move(Request $request, int $id, TitleProgressService $service)
    {
        $progress = TitleProgress::with('orderDetail')->findOrFail($id);
        $service->changeStatus($progress, (string) $request->input('status'), Auth::user(), $request->input('note'));
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

        $service->assignEditor($progress, $userId, Auth::user());
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
        $service->setPriority($progress, (string) $request->input('priority'), Auth::user());
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
