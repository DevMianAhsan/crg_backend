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
    ];
}