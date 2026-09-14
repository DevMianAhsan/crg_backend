<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('company_name');
            $table->timestamp('shifted_at')->useCurrent();
            $table->text('note')->nullable();
            $table->string('shifted_by')->nullable();
            $table->string('share_token')->unique(); // for shareable URL
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_submissions');
    }
};
