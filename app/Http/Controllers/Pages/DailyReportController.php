<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\DailyReport;
use App\Models\DailyReportFile;
use App\Models\User;
use App\Services\DailyReportService;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class DailyReportController extends Controller
{
    public function __construct(private DailyReportService $service, private GoogleDriveService $drive) {}

    private function isManager(): bool
    {
        return Auth::user()->hasAnyRole(['manager', 'superadmin']);
    }

    /** User yang report-nya dilihat (manager boleh ?user_id=); selain itu diri sendiri. */
    private function viewedUser(Request $request): User
    {
        if ($this->isManager() && $request->filled('user_id')) {
            return User::findOrFail((int) $request->input('user_id'));
        }
        return Auth::user();
    }

    private function dateParam(Request $request): Carbon
    {
        return $request->filled('date') ? Carbon::parse($request->input('date')) : Carbon::today();
    }

    public function daily(Request $request)
    {
        $request->validate(['date' => 'nullable|date']);
        $user = $this->viewedUser($request);
        $date = $this->dateParam($request);
        $report = DailyReport::with('files')->where('user_id', $user->id)->whereDate('report_date', $date)->first();

        return view('reports.daily', [
            'recap'     => $this->service->recapFor($user, $date),
            'date'      => $date,
            'owner'     => $user,
            'report'    => $report,
            'files'     => $report?->files ?? collect(),
            'isOwner'   => $user->id === Auth::id(),
            'submitted' => $report?->isSubmitted() ?? false,
        ]);
    }

    public function saveNote(Request $request)
    {
        $data = $request->validate(['date' => 'required|date', 'note' => 'nullable|string']);
        $report = $this->service->getOrCreateReport(Auth::user(), Carbon::parse($data['date']));
        abort_if($report->isSubmitted(), 422, 'Report sudah dikirim.');
        $report->update(['note' => $data['note'] ?? null]);

        return back()->with('success', 'Catatan disimpan.');
    }

    public function submit(Request $request)
    {
        $data = $request->validate(['date' => 'required|date']);
        $report = $this->service->getOrCreateReport(Auth::user(), Carbon::parse($data['date']));

        if (! $report->isSubmitted()) {
            if ($report->files()->count() === 0) {
                return back()->with('error', 'Wajib lampirkan minimal 1 bukti sebelum mengirim report.');
            }
            $report->update(['status' => 'submitted', 'submitted_at' => now()]);
        }

        return back()->with('success', 'Report dikirim.');
    }

    public function storeFile(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx|max:' . \App\Support\BatasUnggah::kb(10240),
        ]);

        $report = $this->service->getOrCreateReport(Auth::user(), Carbon::parse($data['date']));
        if ($report->isSubmitted()) {
            return response()->json(['message' => 'Report sudah dikirim.'], 422);
        }

        $folderId = $this->drive->getOrCreateFolderByPath('SiMAPA/Reports/' . Auth::id() . '/' . Carbon::parse($data['date'])->format('Y-m'));
        if (! $folderId) {
            return response()->json(['message' => 'Gagal membuat folder Google Drive.'], 500);
        }
        $uploaded = $this->drive->uploadFile($request->file('file'), $folderId, true);
        if (! $uploaded) {
            return response()->json(['message' => 'Gagal mengunggah ke Google Drive.'], 500);
        }

        $file = $report->files()->create([
            'drive_file_id' => $uploaded['id'],
            'name'          => $uploaded['name'] ?? $request->file('file')->getClientOriginalName(),
            'url'           => $uploaded['url'] ?? '',
            'mime'          => $request->file('file')->getClientMimeType(),
            'size'          => $request->file('file')->getSize(),
            'uploaded_by'   => Auth::id(),
        ]);

        return response()->json(['id' => $file->id, 'name' => $file->name, 'url' => $file->url]);
    }

    public function destroyFile(int $id)
    {
        $file = DailyReportFile::with('report')->findOrFail($id);
        abort_if($file->report->user_id !== Auth::id(), 403);
        abort_if($file->report->isSubmitted(), 422, 'Report sudah dikirim.');

        if (! $this->drive->deleteFile($file->drive_file_id)) {
            \Illuminate\Support\Facades\Log::warning('Gagal menghapus file Drive: ' . $file->drive_file_id);
        }
        $file->delete();

        return response()->json(['ok' => true]);
    }

    public function monthly(Request $request)
    {
        $request->validate(['month' => 'nullable|date_format:Y-m']);
        $user = $this->viewedUser($request);
        $month = $request->filled('month') ? Carbon::parse($request->input('month') . '-01') : Carbon::today()->startOfMonth();

        return view('reports.monthly', [
            'recap' => $this->service->monthlyRecap($user, (int) $month->year, (int) $month->month),
            'month' => $month,
            'owner' => $user,
        ]);
    }

    public function submissions(Request $request)
    {
        $request->validate(['date' => 'nullable|date']);
        $date = $this->dateParam($request);

        return view('reports.submissions', [
            'rows' => $this->service->submissionsForDate($date),
            'date' => $date,
        ]);
    }
}
