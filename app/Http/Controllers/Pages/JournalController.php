<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JournalController extends Controller
{
    private function canManage(): bool
    {
        return Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin']);
    }

    public function index()
    {
        return view('journals.index', [
            'journals' => Journal::with('scope')->latest()->get(),
            'canManage' => $this->canManage(),
        ]);
    }

    public function create()
    {
        abort_unless($this->canManage(), 403);
        return view('journals.form', ['journal' => new Journal(), 'scopes' => Scope::orderBy('scope')->get()]);
    }

    public function store(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $data = $this->validateData($request);
        $data['scope_id'] = $this->resolveScopeId($data['scope_id'] ?? null);
        $data['created_by'] = Auth::id();
        Journal::create($data);

        return redirect()->route('journal.index')->with('success', 'Jurnal ditambahkan.');
    }

    public function show(int $id)
    {
        return view('journals.show', [
            'journal' => Journal::with(['scope', 'creator'])->findOrFail($id),
            'canManage' => $this->canManage(),
        ]);
    }

    public function edit(int $id)
    {
        abort_unless($this->canManage(), 403);
        return view('journals.form', [
            'journal' => Journal::findOrFail($id),
            'scopes' => Scope::orderBy('scope')->get(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->canManage(), 403);
        $journal = Journal::findOrFail($id);
        $data = $this->validateData($request);
        $data['scope_id'] = $this->resolveScopeId($data['scope_id'] ?? null);
        $journal->update($data);

        return redirect()->route('journal.show', $journal->id)->with('success', 'Jurnal diperbarui.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->canManage(), 403);
        Journal::findOrFail($id)->delete();

        return redirect()->route('journal.index')->with('success', 'Jurnal dihapus.');
    }

    private function resolveScopeId($value): ?int
    {
        if (empty($value)) {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return Scope::firstOrCreate(['scope' => $value])->id;
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'nama'             => 'required|string|max:255',
            'akreditasi'       => 'nullable|string|max:64',
            'scope_id'         => 'nullable|string|max:255',
            'apc_reguler'      => 'nullable|string|max:255',
            'apc_fastrack'     => 'nullable|string|max:255',
            'link'             => 'nullable|string|max:255',
            'kontak_wa'        => 'nullable|string|max:255',
            'kontak_email'     => 'nullable|string|max:255',
            'terbitan_bulan'   => 'nullable|array',
            'terbitan_bulan.*' => 'integer|between:1,12',
            'catatan'          => 'nullable|string',
        ]);
    }
}
