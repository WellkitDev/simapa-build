<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ChapterProgress;
use App\Services\ChapterManuscriptService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ChapterProgressController extends Controller
{
    public function __construct(private ChapterManuscriptService $service) {}

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

    public function advance(Request $request, int $id)
    {
        $cp = ChapterProgress::findOrFail($id);
        $target = (string) $request->input('status');

        if ($redirect = $this->runOrFlash($request, fn () =>
            $this->service->changeStatus($cp, $target, Auth::user(), $request->input('note'))
        )) {
            return $redirect;
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $cp->id, 'status' => $target, 'message' => 'Bab diperbarui.']);
        }
        return back()->with('success', 'Bab diperbarui.');
    }

    public function assign(Request $request, int $id)
    {
        $cp = ChapterProgress::findOrFail($id);
        $raw = $request->input('assigned_user_id');
        $userId = ($raw === null || $raw === '') ? null : (int) $raw;

        if ($redirect = $this->runOrFlash($request, fn () =>
            $this->service->assignEditor($cp, $userId, Auth::user())
        )) {
            return $redirect;
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $cp->id, 'message' => 'Editor bab diperbarui.']);
        }
        return back()->with('success', 'Editor bab diperbarui.');
    }
}
