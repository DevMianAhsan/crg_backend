<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateWithdrawal extends Model
{
    protected $fillable = [
        'candidate_id',
        'stage',
        'deduction_percentage',
        'total_paid_so_far',
        'cancellation_fee',
        'refund_payable',
        'reason',
        'payment_mode',
        'cheque_number',
        'cheque_date',
        'alert_finance_before_days',
        'status',
        'withdrawn_at',
    ];

    protected $casts = [
        'deduction_percentage' => 'decimal:2',
        'total_paid_so_far'    => 'decimal:2',
        'cancellation_fee'     => 'decimal:2',
        'refund_payable'       => 'decimal:2',
        'cheque_date'          => 'date',
        'withdrawn_at'         => 'datetime',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
