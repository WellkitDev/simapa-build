<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\MarketingTarget;
use App\Models\User;
use App\Services\MarketingTargetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MarketingTargetController extends Controller
{
    public function index(Request $request, MarketingTargetService $service)
    {
        $status = $request->input('status'); // null | aktif | berakhir
        $rows = $service->adminList($status);
        $marketers = User::role('marketing')->orderBy('name')->get(['id', 'name']);

        return view('marketing-target.index', compact('rows', 'status', 'marketers'));
    }

    public function store(Request $request, MarketingTargetService $service)
    {
        $data = $request->validate([
            'assignee'        => 'required|string', // 'all' atau id marketing
            'target_amount'   => 'required|numeric|min:0',
            'commission_type' => 'required|in:percent,flat',
            'commission_rate' => 'required_if:commission_type,percent|nullable|numeric|min:0|max:100',
            'commission_flat' => 'required_if:commission_type,flat|nullable|numeric|min:0',
            'start_date'      => 'required|date',
            'end_date'        => 'required|date|after_or_equal:start_date',
        ]);

        $scope   = $data['assignee'] === 'all' ? 'all' : 'individual';
        $userIds = $scope === 'all' ? [] : [(int) $data['assignee']];

        $service->createTarget(
            $scope,
            $userIds,
            (int) $data['target_amount'],
            [
                'type' => $data['commission_type'],
                'rate' => (float) ($data['commission_rate'] ?? 0),
                'flat' => (int) ($data['commission_flat'] ?? 0),
            ],
            $data['start_date'],
            $data['end_date'],
            Auth::user(),
        );

        return redirect()->route('marketing-target.index')->with('success', 'Target dibuat.');
    }

    public function paid(int $id, MarketingTargetService $service)
    {
        $target = MarketingTarget::findOrFail($id);
        $service->markCommissionPaid($target, Auth::user());

        return back()->with('success', 'Komisi ditandai dibayar.');
    }

    public function destroy(int $id)
    {
        $target = MarketingTarget::findOrFail($id);

        if ($target->batch_id) {
            MarketingTarget::where('batch_id', $target->batch_id)->delete();
        } else {
            $target->delete();
        }

        return back()->with('success', 'Target dihapus.');
    }

    public function me(MarketingTargetService $service)
    {
        $rows = $service->listForMarketing(Auth::user());
        return view('target.me', compact('rows'));
    }
}
