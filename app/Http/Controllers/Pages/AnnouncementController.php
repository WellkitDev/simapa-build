<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AnnouncementController extends Controller
{
    public function index()
    {
        $announcements = Announcement::with('creator')->latest()->get();
        return view('announcements.index', compact('announcements'));
    }

    public function create()
    {
        return view('announcements.form', ['announcement' => new Announcement()]);
    }

    public function store(Request $request, AnnouncementService $service)
    {
        $data = $this->validateData($request);

        $a = Announcement::create([
            'title'      => $data['title'],
            'body'       => $this->cleanBody($data['body']),
            'is_pinned'  => $request->boolean('is_pinned'),
            'created_by' => Auth::id(),
            'status'     => 'draft',
        ]);

        if ($data['status'] === 'published') {
            $service->publish($a);
        }

        return redirect()->route('announcement.index')->with('success', 'Pengumuman disimpan.');
    }

    public function edit(int $id)
    {
        return view('announcements.form', ['announcement' => Announcement::findOrFail($id)]);
    }

    public function update(Request $request, int $id, AnnouncementService $service)
    {
        $a = Announcement::findOrFail($id);
        $data = $this->validateData($request);

        $a->update([
            'title'     => $data['title'],
            'body'      => $this->cleanBody($data['body']),
            'is_pinned' => $request->boolean('is_pinned'),
        ]);

        $data['status'] === 'published' ? $service->publish($a) : $a->update(['status' => 'draft']);

        return redirect()->route('announcement.index')->with('success', 'Pengumuman diperbarui.');
    }

    public function destroy(int $id)
    {
        Announcement::findOrFail($id)->delete();
        return back()->with('success', 'Pengumuman dihapus.');
    }

    public function status(Request $request, int $id, AnnouncementService $service)
    {
        $request->validate(['status' => 'required|in:published,archived']);
        $a = Announcement::findOrFail($id);

        $request->input('status') === 'published' ? $service->publish($a) : $service->archive($a);

        return back()->with('success', 'Status pengumuman diperbarui.');
    }

    /** POST dari dashboard semua role: tandai pengumuman dibaca. */
    public function seen(Request $request, AnnouncementService $service)
    {
        $ids = array_map('intval', (array) $request->input('ids', []));
        $service->markSeen(Auth::user(), $ids);

        return response()->noContent();
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'title'     => 'required|string|max:255',
            'body'      => 'required|string',
            'status'    => 'required|in:draft,published',
            'is_pinned' => 'sometimes|boolean',
        ]);
    }

    /** Pengaman ringan: buang <script>/<style> (penulis tepercaya). */
    private function cleanBody(string $html): string
    {
        return preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
    }
}
