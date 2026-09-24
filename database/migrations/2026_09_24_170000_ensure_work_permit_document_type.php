<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('document_types')->where('code', 'WORK_PERMIT')->first();
        if (!$existing) {
            DB::table('document_types')->insert([
                'name' => 'Work Permit',
                'code' => 'WORK_PERMIT',
                'description' => 'Work permit, labor permit, or entry permit document',
                'is_mandatory' => false,
                'validity_months' => 24,
                'requires_expiry_date' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('document_types')->where('code', 'WORK_PERMIT')->delete();
    }
};
