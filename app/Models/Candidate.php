<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Candidate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'psn_code',
        'cnic_number',
        'first_name',
        'last_name',
        'email',
        'phone',
        'passport_number',
        'passport_expiry',
        'passport_series',
        'passport_issued_by',
        'passport_issue_date',
        'passport_history',
        'trade',
        'experience_years',
        'nationality',
        'current_location',
        'target_country',
        'assigned_recruiter',
        'status',
        'recruitment_stage',
        'current_company_id',
        'joined_date',
        'skills',
        'expected_salary',
        'currency',
        'photo_path',
        'balance',
        'father_name',
        'mother_name',
        'place_of_birth',
        'date_of_birth',
        'civil_status',
        'children_count',
        'former_name',
        'citizenship',
        'town',
        'country',
        'occupation_field',
        'cv_summary',
        'cv_data',
        'care_of',
        'age',
        'license',
        'current_job',
        'qualification',
    ];

    protected $casts = [
        'skills'              => 'array',
        'passport_history'    => 'array',
        'cv_data'             => 'array',
        'passport_expiry'     => 'date',
        'passport_issue_date' => 'date',
        'joined_date'         => 'date',
        'date_of_birth'       => 'date',
        'expected_salary'     => 'decimal:2',
        'balance'             => 'decimal:2',
        'experience_years'    => 'integer',
        'age'                 => 'integer',
    ];

    // --------------------------------------------------------------------------
    // Relationships
    // --------------------------------------------------------------------------

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'current_company_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CandidateDocument::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CandidateSubmission::class)->orderByDesc('shifted_at');
    }

    public function withdrawal(): HasOne
    {
        return $this->hasOne(CandidateWithdrawal::class)->latest();
    }

    // --------------------------------------------------------------------------
    // Accessors
    // --------------------------------------------------------------------------

    /** Full public URL for the candidate photo. */
    public function getPhotoUrlAttribute(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        return Storage::disk('public')->url($this->photo_path);
    }
}
