<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Console\Command;

/**
 * Warns staff about passports and candidate documents that expire soon.
 * Each expiry is announced once per user (dedupe key includes the date), so
 * running it daily only alerts about newly-due items.
 */
class NotifyExpiringDocuments extends Command
{
    protected $signature = 'notifications:expiring-documents {--days=30 : Warn this many days ahead}';

    protected $description = 'Notify staff about passports and documents expiring soon';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $until = now()->addDays($days)->toDateString();
        $today = now()->toDateString();
        $staff = User::pluck('id')->all();
        $sent = 0;

        $passports = Candidate::query()
            ->where('status', '!=', 'withdrawn')
            ->whereNotNull('passport_expiry')
            ->whereBetween('passport_expiry', [$today, $until])
            ->get(['id', 'code', 'first_name', 'last_name', 'passport_number', 'passport_expiry']);

        foreach ($passports as $c) {
            $date = $c->passport_expiry->toDateString();
            $left = now()->startOfDay()->diffInDays($c->passport_expiry, false);
            $sent += Notifier::send(
                $staff,
                'DOCUMENT_EXPIRING',
                'Passport expiring soon',
                trim("{$c->first_name} {$c->last_name}") . " ({$c->code}) — passport {$c->passport_number} expires "
                    . ($left <= 0 ? 'today' : "in {$left} day" . ($left === 1 ? '' : 's')) . " ({$date}).",
                ['candidateId' => $c->id, 'documentType' => 'Passport', 'expiryDate' => $date],
                "/dashboard/candidates/{$c->id}",
                "passport-expiry:{$c->id}:{$date}"
            );
        }

        $documents = CandidateDocument::query()
            ->with('candidate:id,code,first_name,last_name,status')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$today, $until])
            ->get();

        foreach ($documents as $d) {
            $c = $d->candidate;
            if (!$c || $c->status === 'withdrawn') {
                continue;
            }
            $date = $d->expiry_date->toDateString();
            $left = now()->startOfDay()->diffInDays($d->expiry_date, false);
            $sent += Notifier::send(
                $staff,
                'DOCUMENT_EXPIRING',
                "{$d->document_type_name} expiring soon",
                trim("{$c->first_name} {$c->last_name}") . " ({$c->code}) — {$d->title} expires "
                    . ($left <= 0 ? 'today' : "in {$left} day" . ($left === 1 ? '' : 's')) . " ({$date}).",
                ['candidateId' => $c->id, 'documentId' => $d->id, 'documentType' => $d->document_type_name, 'expiryDate' => $date],
                "/dashboard/candidates/{$c->id}",
                "document-expiry:{$d->id}:{$date}"
            );
        }

        $this->info("Checked {$passports->count()} passport(s) and {$documents->count()} document(s); {$sent} notification(s) created.");

        return self::SUCCESS;
    }
}
