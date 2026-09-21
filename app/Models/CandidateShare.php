<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateShare extends Model
{
    protected $fillable = [
        'share_token',
        'title',
        'note',
        'company_id',
        'company_name',
        'candidate_ids',
        'created_by',
        'expires_at',
    ];

    protected $casts = [
        'candidate_ids' => 'array',
        'expires_at'    => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
