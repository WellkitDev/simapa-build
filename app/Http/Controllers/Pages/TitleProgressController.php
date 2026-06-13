<?php

namespace App\Http\Controllers\Pages;

use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class TitleProgressController extends Controller
{
    public function update(Request $request, int $id)
    {
        $progress = TitleProgress::with('orderDetail')->findOrFail($id);
        $user     = Auth::user();
        $target   = $request->input('status');

        if ($user->hasRole('marketing')) {
            abort(403);
        }

        if (!$progress->isValidStatus($target)) {
            return back()->with('error', 'Status tidak valid untuk tipe naskah ini.');
        }

        $nextStatus   = $progress->getNextStatus();
        $isCorrection = ($target !== $nextStatus);

        if ($user->hasRole('manager') && $isCorrection) {
            abort(403);
        }

        if ($isCorrection && empty(trim($request->input('note', '')))) {
            return back()->withErrors(['note' => 'Catatan wajib diisi untuk koreksi status.'])->withInput();
        }

        $fromStatus = $progress->status;

        $progress->update([
            'status'        => $target,
            'assigned_role' => TitleProgress::getHandlerForStatus($target),
            'note'          => $request->input('note'),
            'updated_by'    => $user->id,
            'started_at'    => now(),
        ]);

        TitleProgressLog::create([
            'title_progress_id' => $progress->id,
            'from_status'       => $fromStatus,
            'to_status'         => $target,
            'changed_by'        => $user->id,
            'note'              => $request->input('note'),
            'is_correction'     => $isCorrection,
        ]);

        return back()->with('success', 'Status berhasil diperbarui.');
    }

    public function logs(int $id)
    {
        $progress = TitleProgress::with('logs.changedBy')->findOrFail($id);
        return response()->json($progress->logs);
    }
}
