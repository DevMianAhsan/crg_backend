<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            if (!Schema::hasColumn('ledger_entries', 'slip_path')) {
                $table->string('slip_path')->nullable()->after('payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            if (Schema::hasColumn('ledger_entries', 'slip_path')) {
                $table->dropColumn('slip_path');
            }
        });
    }
};
