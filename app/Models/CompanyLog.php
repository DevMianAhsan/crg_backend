<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class CompanyLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'action',
        'description',
        'meta',
        'user_id',
        'user_name',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Records an activity entry for a company, attributed to the request's user. */
    public static function record(int $companyId, string $action, string $description, array $meta = [], ?Request $request = null): self
    {
        $user = $request?->user();

        return self::create([
            'company_id' => $companyId,
            'action' => $action,
            'description' => $description,
            'meta' => $meta ?: null,
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'System',
        ]);
    }
}
