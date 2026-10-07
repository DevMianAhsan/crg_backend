<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CompanyDocument extends Model
{
    protected $fillable = [
        'company_id',
        'title',
        'document_type',
        'document_number',
        'file_path',
        'file_name',
        'file_size',
        'issue_date',
        'expiry_date',
        'status',
        'notes',
        'uploaded_by',
    ];

    protected $casts = [
        'issue_date'  => 'date',
        'expiry_date' => 'date',
    ];

    protected $appends = ['file_url'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Full public URL for the company document file. */
    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return Storage::disk('public')->url($this->file_path);
    }
}
