<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\DocRequirement;
use Illuminate\Http\Request;

class DocRequirementController extends Controller
{
    private function data(Request $request): array
    {
        return $request->validate([
            'category'    => 'required|in:penerbit,hki',
            'label'       => 'required|string|max:200',
            'description' => 'nullable|string',
            'position'    => 'nullable|integer',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->data($request);
        $data['active'] = true;
        DocRequirement::create($data);

        return back()->with('success', 'Item checklist ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        $req = DocRequirement::findOrFail($id);
        $data = $this->data($request);
        $data['active'] = $request->boolean('active', true);
        $req->update($data);

        return back()->with('success', 'Item checklist diperbarui.');
    }

    public function destroy(int $id)
    {
        DocRequirement::findOrFail($id)->delete();

        return back()->with('success', 'Item checklist dihapus.');
    }
}
