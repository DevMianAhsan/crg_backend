<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $casts = [
        'permit_issued' => 'integer',
        'rejected' => 'integer',
        'permit_phases' => 'array',
    ];
}