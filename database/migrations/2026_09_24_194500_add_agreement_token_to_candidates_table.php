<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            if (!Schema::hasColumn('candidates', 'agreement_token')) {
                $table->string('agreement_token', 64)->nullable()->unique()->after('signature_path');
            }
            if (!Schema::hasColumn('candidates', 'terms_agreed_at')) {
                $table->timestamp('terms_agreed_at')->nullable()->after('agreement_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            if (Schema::hasColumn('candidates', 'terms_agreed_at')) {
                $table->dropColumn('terms_agreed_at');
            }
            if (Schema::hasColumn('candidates', 'agreement_token')) {
                $table->dropColumn('agreement_token');
            }
        });
    }
};
