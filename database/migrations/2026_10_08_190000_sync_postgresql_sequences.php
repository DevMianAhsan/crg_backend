<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only relevant for PostgreSQL databases
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tables = [
            'candidates',
            'candidate_documents',
            'candidate_submissions',
            'candidate_withdrawals',
            'candidate_shares',
            'companies',
            'company_logs',
            'company_documents',
            'company_document_templates',
            'document_types',
            'countries',
            'users',
            'drive_documents',
            'ledger_entries',
            'app_notifications',
            'fcm_tokens',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'id')) {
                continue;
            }

            try {
                $maxId = (int) (DB::table($table)->max('id') ?? 0);
                $seqCol = DB::select("SELECT pg_get_serial_sequence('{$table}', 'id') as seq");
                if (!empty($seqCol) && !empty($seqCol[0]->seq)) {
                    $actualSeq = $seqCol[0]->seq;
                    $nextVal = max(1, $maxId);
                    DB::statement("SELECT setval('{$actualSeq}', {$nextVal}, true)");
                }
            } catch (\Throwable) {
                // Ignore any table sequence check errors
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
