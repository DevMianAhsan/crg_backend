<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        spl_autoload_register(function ($class): void {
            $prefixes = [
                'PhpOffice\\PhpSpreadsheet\\' => base_path('vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/'),
                'ZipStream\\'                 => base_path('vendor/maennchen/zipstream-php/src/'),
                'Matrix\\'                    => base_path('vendor/markbaker/matrix/classes/src/'),
                'Complex\\'                   => base_path('vendor/markbaker/complex/classes/src/'),
                'Composer\\Pcre\\'            => base_path('vendor/composer/pcre/src/'),
            ];

            foreach ($prefixes as $prefix => $baseDir) {
                $len = strlen($prefix);
                if (strncmp($prefix, $class, $len) !== 0) {
                    continue;
                }
                $relativeClass = substr($class, $len);
                $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
                if (file_exists($file)) {
                    require_once $file;
                    return;
                }
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $observer = \App\Observers\ModelCacheObserver::class;

        \App\Models\Candidate::observe($observer);
        \App\Models\CandidateDocument::observe($observer);
        \App\Models\CandidateSubmission::observe($observer);
        \App\Models\CandidateWithdrawal::observe($observer);
        \App\Models\CandidateShare::observe($observer);
        \App\Models\Company::observe($observer);
        \App\Models\CompanyLog::observe($observer);
        \App\Models\CompanyDocument::observe($observer);
        \App\Models\CompanyDocumentTemplate::observe($observer);
        \App\Models\DocumentType::observe($observer);
        \App\Models\Country::observe($observer);
        \App\Models\User::observe($observer);
        \App\Models\DriveDocument::observe($observer);
        \App\Models\LedgerEntry::observe($observer);
        \App\Models\AppNotification::observe($observer);
        \App\Models\FcmToken::observe($observer);
        \App\Models\SystemSetting::observe($observer);
    }
}
