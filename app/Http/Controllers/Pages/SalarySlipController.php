<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Jobs\SendSalarySlipJob;
use App\Models\SalarySlip;
use App\Models\User;
use App\Services\Notifier;
use App\Support\SalarySlipPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SalarySlipController extends Controller
{
    public function index(Request $request)
    {
        $now   = now();
        $year  = (int) $request->query('year', $now->year);
        $mq    = $request->query('month', (string) $now->month);
        $month = ($mq === 'all') ? null : (int) ($mq ?: $now->month);
        $eq    = $request->query('employee');
        $employeeId = ($eq === null || $eq === '' || $eq === 'all') ? null : (int) $eq;
        $status = in_array($request->query('status'), ['draft', 'terbit'], true) ? $request->query('status') : null;

        $slips = SalarySlip::query()
            ->where('period_year', $year)
            ->when($month, fn ($q) => $q->where('period_month', $month))
            ->when($employeeId, fn ($q) => $q->where('user_id', $employeeId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id')
            ->get();

        $employees = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $years     = range($now->year, $now->year - 4);

        return view('salary.slips.index', compact('slips', 'employees', 'year', 'month', 'employeeId', 'status', 'years'));
    }

    public function create()
    {
        $employees = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $now = now();

        return view('salary.slips.form', [
            'slip'       => new SalarySlip(['period_year' => $now->year, 'period_month' => $now->month, 'status' => 'draft']),
            'employees'  => $employees,
            'earnings'   => collect(),
            'deductions' => collect(),
            'mode'       => 'create',
        ]);
    }

    public function store(Request $request)
    {
        $this->normalizeAmounts($request);
        $data = $request->validate($this->baseRules());

        // Duplikat dicek di lapisan validasi (bukan unique index DB — tabel soft-delete). Race TOCTOU
        // diterima untuk alat internal berpengguna tunggal (satu accountant).
        if ($this->periodTaken($data['user_id'], $data['period_year'], $data['period_month'])) {
            return back()->withInput()->withErrors(['user_id' => 'Slip untuk karyawan & periode ini sudah ada.']);
        }

        $employee = User::with('profile')->findOrFail($data['user_id']);

        DB::transaction(function () use ($data, $employee) {
            $slip = SalarySlip::create([
                'slip_no'           => $this->generateSlipNo($data['period_year'], $data['period_month']),
                'user_id'           => $employee->id,
                'employee_name'     => $employee->name,
                'employee_position' => optional($employee->profile)->job_name,
                'period_year'       => $data['period_year'],
                'period_month'      => $data['period_month'],
                'status'            => 'draft',
                'note'              => $data['note'] ?? null,
                'created_by'        => Auth::id(),
            ]);
            $this->syncLines($slip, $data);
            $slip->recalcTotals();
        });

        return redirect()->route('salary.slip.index')->with('success', 'Slip gaji dibuat.');
    }

    /** Aturan validasi bersama create & update. */
    private function baseRules(): array
    {
        return [
            'user_id'             => 'required|exists:users,id',
            'period_year'         => 'required|integer|min:2000|max:2100',
            'period_month'        => 'required|integer|min:1|max:12',
            'note'                => 'nullable|string',
            'earnings'            => 'required|array|min:1',
            'earnings.*.label'    => 'required|string|max:150',
            'earnings.*.amount'   => 'required|numeric|min:0|max:9999999999999.99',
            'deductions'          => 'nullable|array',
            'deductions.*.label'  => 'required|string|max:150',
            'deductions.*.amount' => 'required|numeric|min:0|max:9999999999999.99',
        ];
    }

    /** Buang pemisah ribuan (mis. "1.000.000" -> "1000000") sebelum validasi. Defensif. */
    private function normalizeAmounts(Request $request): void
    {
        foreach (['earnings', 'deductions'] as $group) {
            $rows = $request->input($group, []);
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $i => $row) {
                if (isset($row['amount'])) {
                    // Hanya buang pemisah ribuan (titik/koma/spasi); pertahankan tanda minus
                    // agar nominal negatif tetap DITOLAK oleh aturan min:0 (bukan diam-diam dibalik).
                    $rows[$i]['amount'] = preg_replace('/[.,\s]/', '', (string) $row['amount']);
                }
            }
            $request->merge([$group => $rows]);
        }
    }

    private function periodTaken(int $userId, int $year, int $month, ?int $ignoreId = null): bool
    {
        return SalarySlip::where('user_id', $userId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    private function generateSlipNo(int $year, int $month): string
    {
        $prefix = sprintf('SLP-%04d%02d-', $year, $month);
        $seq    = SalarySlip::withTrashed()->where('slip_no', 'like', $prefix . '%')->count() + 1;
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** Buat ulang baris earning & deduction dari input tervalidasi. */
    private function syncLines(SalarySlip $slip, array $data): void
    {
        $rows = [];
        $pos  = 0;
        foreach ($data['earnings'] ?? [] as $e) {
            if (($e['label'] ?? '') === '') {
                continue;
            }
            $rows[] = ['type' => 'earning', 'label' => $e['label'], 'amount' => $e['amount'] ?? 0, 'position' => $pos++];
        }
        $pos = 0;
        foreach ($data['deductions'] ?? [] as $d) {
            if (($d['label'] ?? '') === '') {
                continue;
            }
            $rows[] = ['type' => 'deduction', 'label' => $d['label'], 'amount' => $d['amount'] ?? 0, 'position' => $pos++];
        }
        $slip->lines()->createMany($rows);
    }
}
