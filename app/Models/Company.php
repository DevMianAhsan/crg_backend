<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name',
        'industry',
        'contact_person',
        'contact_email',
        'contact_phone',
        'country',
        'city',
        'status',
        'permit_issued',
        'rejected',
        'permit_phases',
        'shared_candidates_count',
    ];

    protected $casts = [
        'permit_issued' => 'integer',
        'rejected' => 'integer',
        'permit_phases' => 'array',
        'shared_candidates_count' => 'integer',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(CompanyLog::class)->latest('created_at')->latest('id');
    }
}
