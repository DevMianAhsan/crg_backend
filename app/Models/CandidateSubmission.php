<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateSubmission extends Model
{
    protected $fillable = [
        'candidate_id',
        'company_id',
        'company_name',
        'shifted_at',
        'note',
        'shifted_by',
        'share_token',
    ];

    protected $casts = [
        'shifted_at' => 'datetime',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
