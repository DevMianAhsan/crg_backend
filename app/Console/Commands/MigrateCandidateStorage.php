<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateCandidateStorage extends Command
{
    protected $signature = 'candidates:migrate-storage';
    protected $description = 'Migrate candidate photos and signatures directly into individual candidate folders';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $candidates = Candidate::whereNotNull('photo_path')
            ->orWhereNotNull('signature_path')
            ->get();

        $this->info("Found {$candidates->count()} candidates with photo or signature.");

        foreach ($candidates as $candidate) {
            // Migrate photo to candidates/{id}/{filename}
            if ($candidate->photo_path) {
                if (str_starts_with($candidate->photo_path, 'candidates/photos/') || preg_match('#^candidates/\d+/photos/#', $candidate->photo_path)) {
                    $filename = basename($candidate->photo_path);
                    $newPath = "candidates/{$candidate->id}/{$filename}";
                    if ($disk->exists($candidate->photo_path)) {
                        $disk->move($candidate->photo_path, $newPath);
                        $this->info("Moved candidate #{$candidate->id} photo to {$newPath}");
                    }
                    $candidate->update(['photo_path' => $newPath]);
                }
            }

            // Migrate signature to candidates/{id}/{filename}
            if ($candidate->signature_path) {
                if (str_starts_with($candidate->signature_path, 'candidates/signatures/') || preg_match('#^candidates/\d+/signatures/#', $candidate->signature_path)) {
                    $filename = basename($candidate->signature_path);
                    $newPath = "candidates/{$candidate->id}/{$filename}";
                    if ($disk->exists($candidate->signature_path)) {
                        $disk->move($candidate->signature_path, $newPath);
                        $this->info("Moved candidate #{$candidate->id} signature to {$newPath}");
                    }
                    $candidate->update(['signature_path' => $newPath]);
                }
            }

            // Clean up empty candidate subfolders photos / signatures
            if ($disk->exists("candidates/{$candidate->id}/photos")) {
                $disk->deleteDirectory("candidates/{$candidate->id}/photos");
            }
            if ($disk->exists("candidates/{$candidate->id}/signatures")) {
                $disk->deleteDirectory("candidates/{$candidate->id}/signatures");
            }
        }

        // Clean up root photos and signatures folders if any exist
        if ($disk->exists('candidates/photos')) {
            $disk->deleteDirectory('candidates/photos');
            $this->info("Deleted root 'candidates/photos' directory.");
        }
        if ($disk->exists('candidates/signatures')) {
            $disk->deleteDirectory('candidates/signatures');
            $this->info("Deleted root 'candidates/signatures' directory.");
        }

        $this->info("Migration completed successfully!");
        return Command::SUCCESS;
    }
}
