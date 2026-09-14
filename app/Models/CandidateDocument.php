<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CandidateDocument extends Model
{
    protected $fillable = [
        'candidate_id',
        'title',
        'document_type_id',
        'document_type_name',
        'file_path',
        'file_name',
        'file_size',
        'issue_date',
        'expiry_date',
        'status',
        'verified_by',
        'notes',
    ];

    protected $casts = [
        'issue_date'  => 'date',
        'expiry_date' => 'date',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** Full public URL for the document file. */
    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return Storage::disk('public')->url($this->file_path);
    }
}
