<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE candidates DROP CONSTRAINT IF EXISTS candidates_status_check;');
        DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_status_check CHECK (status::text = ANY (ARRAY['available'::text, 'active'::text, 'placed'::text, 'processing'::text, 'on_hold'::text, 'withdrawn'::text, 'archived'::text]));");
        DB::statement("ALTER TABLE candidates ALTER COLUMN status SET DEFAULT 'processing';");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE candidates DROP CONSTRAINT IF EXISTS candidates_status_check;');
        DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_status_check CHECK (status::text = ANY (ARRAY['available'::text, 'placed'::text, 'processing'::text, 'on_hold'::text, 'withdrawn'::text, 'archived'::text]));");
        DB::statement("ALTER TABLE candidates ALTER COLUMN status SET DEFAULT 'available';");
    }
};
