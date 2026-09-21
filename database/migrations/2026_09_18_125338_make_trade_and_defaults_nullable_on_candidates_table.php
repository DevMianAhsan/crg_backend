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
            $table->string('trade')->nullable()->change();
            $table->string('last_name')->nullable()->change();
            $table->string('nationality')->nullable()->default(null)->change();
            $table->string('current_location')->nullable()->default(null)->change();
            $table->string('currency')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('trade')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
            $table->string('nationality')->default('Pakistani')->nullable(false)->change();
            $table->string('current_location')->default('Pakistan')->nullable(false)->change();
            $table->string('currency')->default('AED')->nullable(false)->change();
        });
    }
};
