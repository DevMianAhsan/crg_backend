<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'is_mandatory',
        'validity_months',
        'requires_expiry_date',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'requires_expiry_date' => 'boolean',
            'validity_months' => 'integer',
        ];
    }
}