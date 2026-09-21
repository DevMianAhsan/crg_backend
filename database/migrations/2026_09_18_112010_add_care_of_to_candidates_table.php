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
            if (!Schema::hasColumn('candidates', 'care_of')) {
                $table->string('care_of')->nullable();
            }
            if (!Schema::hasColumn('candidates', 'age')) {
                $table->unsignedSmallInteger('age')->nullable();
            }
            if (!Schema::hasColumn('candidates', 'license')) {
                $table->string('license')->nullable();
            }
            if (!Schema::hasColumn('candidates', 'current_job')) {
                $table->string('current_job')->nullable();
            }
            if (!Schema::hasColumn('candidates', 'qualification')) {
                $table->string('qualification')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $cols = ['care_of', 'age', 'license', 'current_job', 'qualification'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('candidates', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
