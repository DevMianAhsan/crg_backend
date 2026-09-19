<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LedgerEntry extends Model
{
    use SoftDeletes;

    protected $table = 'ledger_entries';

    protected $fillable = [
        'transaction_no',
        'date',
        'candidate_id',
        'company_id',
        'type',
        'amount',
        'currency',
        'status',
        'description',
        'payment_method',
        'cheque_number',
        'cheque_date',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'date' => 'date:Y-m-d',
        'cheque_date' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::creating(function (LedgerEntry $entry): void {
            if (empty($entry->transaction_no)) {
                $year = date('Y');
                $count = static::withTrashed()->whereYear('created_at', $year)->count() + 1;
                $entry->transaction_no = sprintf('TXN-%s-%04d', $year, $count);
            }
            if (empty($entry->date)) {
                $entry->date = now()->toDateString();
            }
        });
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
