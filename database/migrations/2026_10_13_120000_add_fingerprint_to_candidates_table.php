<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            if (!Schema::hasColumn('candidates', 'fingerprint_path')) {
                $table->string('fingerprint_path')->nullable()->after('signature_path');
            }
            if (!Schema::hasColumn('candidates', 'fingerprint_template')) {
                $table->text('fingerprint_template')->nullable()->after('fingerprint_path');
            }
            if (!Schema::hasColumn('candidates', 'fingerprint_quality')) {
                $table->integer('fingerprint_quality')->nullable()->after('fingerprint_template');
            }
            if (!Schema::hasColumn('candidates', 'fingerprint_captured_at')) {
                $table->timestamp('fingerprint_captured_at')->nullable()->after('fingerprint_quality');
            }
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            $table->dropColumn([
                'fingerprint_path',
                'fingerprint_template',
                'fingerprint_quality',
                'fingerprint_captured_at',
            ]);
        });
    }
};
