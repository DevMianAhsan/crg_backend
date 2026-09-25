<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Every candidate ever shifted to this company. Never decremented, so
            // rejected / returned candidates still count toward the total.
            $table->unsignedInteger('shared_candidates_count')->default(0)->after('status');
        });

        // Backfill from shift history: distinct candidates per company
        $counts = DB::table('candidate_submissions')
            ->select('company_id', DB::raw('COUNT(DISTINCT candidate_id) as total'))
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        foreach ($counts as $companyId => $total) {
            DB::table('companies')->where('id', $companyId)->update(['shared_candidates_count' => (int) $total]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('shared_candidates_count');
        });
    }
};
