<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalarySlip extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tb_salary_slips';

    protected $fillable = [
        'slip_no', 'user_id', 'employee_name', 'employee_position',
        'period_year', 'period_month', 'status',
        'total_earnings', 'total_deductions', 'net_pay',
        'note', 'sent_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'period_year'      => 'integer',
        'period_month'     => 'integer',
        'total_earnings'   => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_pay'          => 'decimal:2',
        'sent_at'          => 'datetime',
    ];

    const STATUS = ['draft' => 'Draft', 'terbit' => 'Terbit'];

    const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function lines()
    {
        return $this->hasMany(SalarySlipLine::class)->orderBy('position')->orderBy('id');
    }

    public function earnings()
    {
        return $this->hasMany(SalarySlipLine::class)->where('type', 'earning')->orderBy('position')->orderBy('id');
    }

    public function deductions()
    {
        return $this->hasMany(SalarySlipLine::class)->where('type', 'deduction')->orderBy('position')->orderBy('id');
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function periodLabel(): string
    {
        return (self::MONTHS[$this->period_month] ?? $this->period_month) . ' ' . $this->period_year;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function recalcTotals(): void
    {
        $earn = (float) $this->lines()->where('type', 'earning')->sum('amount');
        $ded  = (float) $this->lines()->where('type', 'deduction')->sum('amount');
        $this->update([
            'total_earnings'   => $earn,
            'total_deductions' => $ded,
            'net_pay'          => $earn - $ded,
        ]);
    }
}
