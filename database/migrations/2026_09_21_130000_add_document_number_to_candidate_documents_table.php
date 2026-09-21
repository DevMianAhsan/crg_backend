<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_documents', function (Blueprint $table): void {
            if (!Schema::hasColumn('candidate_documents', 'document_number')) {
                $table->string('document_number')->nullable()->after('document_type_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('candidate_documents', function (Blueprint $table): void {
            if (Schema::hasColumn('candidate_documents', 'document_number')) {
                $table->dropColumn('document_number');
            }
        });
    }
};
