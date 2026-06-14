<?php

namespace App\Http\Controllers\Pages;

use App\Models\TitleProgress;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class TitleProgressController extends Controller
{
    public function update(Request $request, int $id, \App\Services\TitleProgressService $service)
    {
        $progress = TitleProgress::with('orderDetail')->findOrFail($id);

        $service->changeStatus(
            $progress,
            (string) $request->input('status'),
            Auth::user(),
            $request->input('note')
        );

        return back()->with('success', 'Status berhasil diperbarui.');
    }

    public function logs(int $id)
    {
        $progress = TitleProgress::with('logs.changedBy')->findOrFail($id);
        return response()->json($progress->logs);
    }
}
