<?php

namespace App\Console\Commands;

use App\Models\CandidateDocument;
use App\Models\DriveDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Lists database records whose uploaded file is not on this server's disk —
 * e.g. after copying the database from another machine without storage/app/public.
 */
class ReportMissingFiles extends Command
{
    protected $signature = 'storage:missing-files';

    protected $description = 'List candidate documents and drive files whose file is missing from storage';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $rows = [];

        CandidateDocument::with('candidate:id,code,first_name,last_name')
            ->orderBy('candidate_id')
            ->each(function (CandidateDocument $d) use ($disk, &$rows) {
                if ($d->file_path && $disk->exists($d->file_path)) {
                    return;
                }
                $c = $d->candidate;
                $rows[] = [
                    'Candidate',
                    $c ? "{$c->code} " . trim("{$c->first_name} {$c->last_name}") : "#{$d->candidate_id}",
                    $d->title,
                    $d->file_path ?: '(no path)',
                ];
            });

        DriveDocument::query()->orderBy('id')->each(function (DriveDocument $d) use ($disk, &$rows) {
            if ($d->file_path && $disk->exists($d->file_path)) {
                return;
            }
            $rows[] = ['Drive', '—', $d->name, $d->file_path ?: '(no path)'];
        });

        if (!$rows) {
            $this->info('All uploaded files are present.');

            return self::SUCCESS;
        }

        $this->table(['Where', 'Candidate', 'Document', 'Expected at storage/app/public/'], $rows);
        $this->warn(count($rows) . ' file(s) missing. Copy storage/app/public from the machine they were uploaded on, or re-upload them.');

        return self::SUCCESS;
    }
}
