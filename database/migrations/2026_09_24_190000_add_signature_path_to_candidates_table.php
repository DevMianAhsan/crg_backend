<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            if (!Schema::hasColumn('candidates', 'signature_path')) {
                $table->string('signature_path')->nullable()->after('photo_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            if (Schema::hasColumn('candidates', 'signature_path')) {
                $table->dropColumn('signature_path');
            }
        });
    }
};
