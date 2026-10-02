<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            if (Schema::hasColumn('candidates', 'balance') && !Schema::hasColumn('candidates', 'service_charges')) {
                $table->renameColumn('balance', 'service_charges');
            } elseif (!Schema::hasColumn('candidates', 'service_charges')) {
                $table->decimal('service_charges', 12, 2)->default(0);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            if (Schema::hasColumn('candidates', 'service_charges') && !Schema::hasColumn('candidates', 'balance')) {
                $table->renameColumn('service_charges', 'balance');
            }
        });
    }
};
