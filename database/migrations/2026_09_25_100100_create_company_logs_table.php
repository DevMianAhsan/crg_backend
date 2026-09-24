<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // created | updated | permits_updated | candidates_shifted | candidate_rejected
            $table->string('action', 50);
            $table->string('description');
            $table->json('meta')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
        });

        // Seed history so existing companies don't start with an empty log
        foreach (DB::table('companies')->get(['id', 'name', 'created_at']) as $company) {
            DB::table('company_logs')->insert([
                'company_id' => $company->id,
                'action' => 'created',
                'description' => "Company \"{$company->name}\" registered",
                'created_at' => $company->created_at ?? now(),
            ]);
        }

        // One log per past shift batch (same company, time and user)
        $batches = DB::table('candidate_submissions as s')
            ->leftJoin('candidates as c', 'c.id', '=', 's.candidate_id')
            ->select('s.company_id', 's.shifted_at', 's.shifted_by', 's.note', 's.candidate_id', 'c.first_name', 'c.last_name')
            ->orderBy('s.shifted_at')
            ->get()
            ->groupBy(fn ($row) => $row->company_id . '|' . $row->shifted_at . '|' . $row->shifted_by);

        foreach ($batches as $rows) {
            $first = $rows->first();
            $names = $rows->map(fn ($r) => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')))->filter()->values();
            $count = $rows->count();
            DB::table('company_logs')->insert([
                'company_id' => $first->company_id,
                'action' => 'candidates_shifted',
                'description' => $count === 1
                    ? "Shifted {$names->first()} to the company"
                    : "Shifted {$count} candidates to the company",
                'meta' => json_encode([
                    'candidateIds' => $rows->pluck('candidate_id')->values(),
                    'candidateNames' => $names,
                    'note' => $first->note,
                ]),
                'user_name' => $first->shifted_by,
                'created_at' => $first->shifted_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_logs');
    }
};
