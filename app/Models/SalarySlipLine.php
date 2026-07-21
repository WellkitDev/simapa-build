<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalarySlipLine extends Model
{
    use HasFactory;

    protected $table = 'tb_salary_slip_lines';

    protected $fillable = ['salary_slip_id', 'type', 'label', 'amount', 'position'];

    protected $casts = ['amount' => 'decimal:2', 'position' => 'integer'];

    public function slip()
    {
        return $this->belongsTo(SalarySlip::class, 'salary_slip_id');
    }
}
