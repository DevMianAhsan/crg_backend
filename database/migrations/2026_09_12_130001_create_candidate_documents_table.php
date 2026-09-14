<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->string('title');
            $table->string('document_type_id')->default('doc-type-1');
            $table->string('document_type_name')->default('Compliance Document');
            $table->string('file_path')->nullable(); // stored path on disk
            $table->string('file_name')->nullable();
            $table->string('file_size')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->enum('status', ['verified', 'expiring', 'expired', 'pending', 'rejected'])->default('pending');
            $table->string('verified_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_documents');
    }
};
