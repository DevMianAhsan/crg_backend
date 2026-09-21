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
            $table->string('former_name')->nullable()->after('last_name');
            $table->string('citizenship')->nullable()->after('nationality');
            $table->string('town')->nullable()->after('current_location');
            $table->string('country')->nullable()->after('town');
            $table->string('occupation_field')->nullable()->after('trade');
            $table->text('cv_summary')->nullable()->after('balance');
            $table->json('cv_data')->nullable()->after('cv_summary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn([
                'former_name',
                'citizenship',
                'town',
                'country',
                'occupation_field',
                'cv_summary',
                'cv_data',
            ]);
        });
    }
};
