<?php

namespace App\Http\Controllers\Pages;

use App\Exceptions\DataAssetAccessException;
use App\Http\Controllers\Controller;
use App\Models\DataAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DataAssetController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $assets = DataAsset::with('owner')
            ->where('owner_id', $user->id)->orWhere('visibility', 'shared')
            ->latest()->get()
            ->filter(fn ($a) => $a->canView($user))->values();

        return view('data-assets.index', compact('assets'));
    }

    public function create()
    {
        $asset = null;
        return view('data-assets.create', compact('asset'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $payload = $this->payload($request, $data);
        $payload['owner_id'] = Auth::id();
        DataAsset::create($payload);

        return redirect()->route('data.index')->with('success', 'Data ditambahkan ke gudang.');
    }

    public function edit(int $id)
    {
        $asset = $this->findOwned($id);

        return view('data-assets.edit', compact('asset'));
    }

    public function update(Request $request, int $id)
    {
        $asset = $this->findOwned($id);

        $data = $this->validated($request, false);
        $payload = $this->payload($request, $data, $asset);
        $asset->update($payload);

        return redirect()->route('data.index')->with('success', 'Data diperbarui.');
    }

    public function download(int $id)
    {
        $asset = $this->findViewable($id);
        abort_if($asset->type !== 'file' || ! $asset->file_path, 404);

        return Storage::download($asset->file_path, $asset->file_name);
    }

    public function destroy(int $id)
    {
        $asset = $this->findOwned($id);

        if ($asset->type === 'file' && $asset->file_path) {
            Storage::delete($asset->file_path);
        }
        $asset->delete();

        return redirect()->route('data.index')->with('success', 'Data dihapus.');
    }

    private function findViewable(int $id): DataAsset
    {
        $asset = DataAsset::find($id);
        if (! $asset) {
            throw DataAssetAccessException::notFound();
        }
        if (! $asset->canView(Auth::user())) {
            throw DataAssetAccessException::cannotView();
        }

        return $asset;
    }

    private function findOwned(int $id): DataAsset
    {
        $asset = DataAsset::find($id);
        if (! $asset) {
            throw DataAssetAccessException::notFound();
        }
        if (! $asset->isOwner(Auth::user())) {
            throw DataAssetAccessException::notOwner();
        }

        return $asset;
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name'           => 'required|string|max:150',
            'description'    => 'nullable|string',
            'type'           => 'required|in:link,file',
            'url'            => [$creating ? 'required_if:type,link' : 'nullable', 'nullable', 'url', 'max:1000'],
            'file'           => [$creating ? 'required_if:type,file' : 'nullable', 'nullable', 'file', 'mimes:xlsx,xls,csv', 'max:' . \App\Support\BatasUnggah::kb(10240)],
            'visibility'     => 'required|in:private,shared',
            'shared_roles'   => 'nullable|array',
            'shared_roles.*' => 'string',
        ]);
    }

    private function payload(Request $request, array $data, ?DataAsset $asset = null): array
    {
        $payload = [
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'type'         => $data['type'],
            'visibility'   => $data['visibility'],
            'shared_roles' => $data['visibility'] === 'shared' ? ($data['shared_roles'] ?? []) : null,
            'updated_by'   => Auth::id(),
        ];

        if ($data['type'] === 'link') {
            $payload['url'] = $data['url'] ?? ($asset->url ?? null);
            $payload['file_path'] = null; $payload['file_name'] = null; $payload['file_size'] = null;
            if ($asset && $asset->file_path) { Storage::delete($asset->file_path); }
        } else {
            if ($request->hasFile('file')) {
                if ($asset && $asset->file_path) { Storage::delete($asset->file_path); }
                $f = $request->file('file');
                $payload['file_path'] = $f->store('data-assets');
                $payload['file_name'] = $f->getClientOriginalName();
                $payload['file_size'] = $f->getSize();
            }
            $payload['url'] = null;
        }

        return $payload;
    }
}
