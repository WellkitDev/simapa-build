<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Title;
use App\Services\DocChecklistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TitleDocCheckController extends Controller
{
    public function __construct(private DocChecklistService $service) {}

    public function save(Request $request, int $id)
    {
        $title = Title::findOrFail($id);
        abort_unless($title->jenis === 'buku', 403);

        $marks = (array) $request->input('marks', []);
        $items = [];
        foreach ($marks as $rid => $m) {
            $items[] = [
                'requirement_id' => (int) $rid,
                'status'         => $m['status'] ?? 'belum',
                'catatan'        => $m['catatan'] ?? null,
                'file'           => $request->file("marks.$rid.file"),
            ];
        }
        $this->service->saveMarks($title, $items, Auth::user());

        return redirect()->route('title.show', $id)->with('success', 'Kelengkapan dokumen disimpan.');
    }

    public function submit(int $id)
    {
        $title = Title::findOrFail($id);
        abort_unless($title->jenis === 'buku', 403);
        $this->service->submit($title, Auth::user());

        return redirect()->route('title.show', $id)->with('success', 'Kelengkapan dokumen diajukan.');
    }
}
