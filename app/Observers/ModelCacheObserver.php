<?php

namespace App\Observers;

use App\Models\AppNotification;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\CandidateShare;
use App\Models\CandidateSubmission;
use App\Models\CandidateWithdrawal;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentTemplate;
use App\Models\CompanyLog;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\DriveDocument;
use App\Models\FcmToken;
use App\Models\LedgerEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CacheService;
use Illuminate\Database\Eloquent\Model;

class ModelCacheObserver
{
    /**
     * Map model classes to their associated cache groups.
     */
    protected static array $modelGroupMap = [
        Candidate::class               => [CacheService::GROUP_CANDIDATES, CacheService::GROUP_COMPANIES, CacheService::GROUP_LEDGER],
        CandidateDocument::class       => [CacheService::GROUP_CANDIDATES],
        CandidateSubmission::class     => [CacheService::GROUP_CANDIDATES, CacheService::GROUP_COMPANIES],
        CandidateWithdrawal::class     => [CacheService::GROUP_CANDIDATES, CacheService::GROUP_LEDGER],
        CandidateShare::class          => [CacheService::GROUP_CANDIDATES],
        Company::class                 => [CacheService::GROUP_COMPANIES, CacheService::GROUP_CANDIDATES],
        CompanyLog::class              => [CacheService::GROUP_COMPANIES],
        CompanyDocument::class         => [CacheService::GROUP_COMPANIES],
        CompanyDocumentTemplate::class => [CacheService::GROUP_COMPANIES],
        DocumentType::class            => [CacheService::GROUP_DOCUMENT_TYPES, CacheService::GROUP_CANDIDATES],
        Country::class                 => [CacheService::GROUP_COUNTRIES],
        User::class                    => [CacheService::GROUP_STAFF],
        DriveDocument::class           => [CacheService::GROUP_DRIVE],
        LedgerEntry::class             => [CacheService::GROUP_LEDGER, CacheService::GROUP_CANDIDATES],
        AppNotification::class         => [CacheService::GROUP_NOTIFICATIONS],
        FcmToken::class                => [CacheService::GROUP_NOTIFICATIONS],
        SystemSetting::class           => [CacheService::GROUP_SETTINGS],
    ];

    /**
     * Invalidate relevant cache groups when a model event occurs.
     */
    protected function invalidateForModel(Model $model): void
    {
        $class = get_class($model);
        $groups = self::$modelGroupMap[$class] ?? [CacheService::GROUP_CANDIDATES];

        CacheService::invalidateGroup($groups);
    }

    public function created(Model $model): void
    {
        $this->invalidateForModel($model);
    }

    public function updated(Model $model): void
    {
        $this->invalidateForModel($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidateForModel($model);
    }

    public function restored(Model $model): void
    {
        $this->invalidateForModel($model);
    }

    public function forceDeleted(Model $model): void
    {
        $this->invalidateForModel($model);
    }
}
