<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CustomSheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomSheetController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $sheets = CustomSheet::with('owner')
            ->where('owner_id', $user->id)->orWhere('visibility', 'shared')
            ->latest()->get()
            ->filter(fn ($s) => $s->canView($user));

        $mine   = $sheets->where('owner_id', $user->id)->values();
        $shared = $sheets->where('owner_id', '!=', $user->id)->values();

        // TODO(Task 3): return view('sheets.index', compact('mine', 'shared'));
        return response($mine->concat($shared)->pluck('name')->implode(' | '));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:150']);
        $sheet = CustomSheet::create([
            'name'       => $data['name'],
            'owner_id'   => Auth::id(),
            'visibility' => 'private',
            'columns'    => [],
            'data'       => array_fill(0, 15, array_fill(0, 6, '')),
            'updated_by' => Auth::id(),
        ]);

        return redirect()->route('sheet.show', $sheet->id)->with('success', 'Lembar dibuat.');
    }

    public function show(int $id)
    {
        $sheet = CustomSheet::with('owner')->findOrFail($id);
        abort_unless($sheet->canView(Auth::user()), 403);

        $canEdit = $sheet->canEdit(Auth::user());

        // TODO(Task 3): return view('sheets.show', compact('sheet', 'canEdit'));
        return response('SHEET ' . $sheet->name);
    }

    public function update(Request $request, int $id)
    {
        $sheet = CustomSheet::findOrFail($id);
        abort_unless($sheet->isOwner(Auth::user()), 403);

        $data = $request->validate([
            'name'           => 'required|string|max:150',
            'visibility'     => 'required|in:private,shared',
            'shared_roles'   => 'nullable|array',
            'shared_roles.*' => 'string',
        ]);
        $sheet->update([
            'name'         => $data['name'],
            'visibility'   => $data['visibility'],
            'shared_roles' => $data['visibility'] === 'shared' ? ($data['shared_roles'] ?? []) : null,
        ]);

        return back()->with('success', 'Setelan lembar diperbarui.');
    }

    public function save(Request $request, int $id)
    {
        $sheet = CustomSheet::findOrFail($id);
        abort_unless($sheet->canEdit(Auth::user()), 403);

        $data = $request->validate([
            'data'    => 'nullable|array',
            'columns' => 'nullable|array',
        ]);
        $sheet->update([
            'data'       => $data['data'] ?? [],
            'columns'    => $data['columns'] ?? [],
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['ok' => true, 'saved_at' => now()->format('H:i:s')]);
    }

    public function destroy(int $id)
    {
        $sheet = CustomSheet::findOrFail($id);
        abort_unless($sheet->isOwner(Auth::user()), 403);
        $sheet->delete();

        return redirect()->route('sheet.index')->with('success', 'Lembar dihapus.');
    }
}
