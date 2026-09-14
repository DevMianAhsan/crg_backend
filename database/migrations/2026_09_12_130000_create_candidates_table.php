<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique(); // e.g. CRG-1001
            $table->string('psn_code')->nullable(); // e.g. PSN-2026-0001
            $table->string('cnic_number')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('passport_number');
            $table->date('passport_expiry')->nullable();
            $table->string('passport_series')->nullable();
            $table->string('passport_issued_by')->nullable();
            $table->date('passport_issue_date')->nullable();
            $table->string('trade');
            $table->unsignedInteger('experience_years')->default(0);
            $table->string('nationality')->default('Pakistani');
            $table->string('current_location')->default('Pakistan');
            $table->string('target_country')->nullable();
            $table->string('assigned_recruiter')->nullable();
            $table->enum('status', ['available', 'placed', 'processing', 'on_hold', 'withdrawn', 'archived'])->default('available');
            $table->enum('recruitment_stage', ['inquiry', 'registered', 'docs_collection', 'processing', 'visa_applied', 'visa_stamped', 'ready_to_fly'])->default('registered');
            $table->foreignId('current_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->date('joined_date')->nullable();
            $table->json('skills')->default('[]');
            $table->decimal('expected_salary', 10, 2)->default(0);
            $table->string('currency')->default('AED');
            $table->string('photo_path')->nullable(); // stored path on disk
            $table->decimal('balance', 12, 2)->default(0);
            // Extended personal info (for CV)
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('civil_status')->nullable();
            $table->string('children_count')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
