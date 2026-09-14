<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedInteger('validity_months')->default(12);
            $table->boolean('requires_expiry_date')->default(true);
            $table->timestamps();
        });

        DB::table('document_types')->insert([
            ['name' => 'Passport', 'code' => 'PASSPORT', 'description' => 'International travel passport with at least 6 months validity', 'is_mandatory' => true, 'validity_months' => 60, 'requires_expiry_date' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Medical Fitness Certificate', 'code' => 'MEDICAL_FITNESS', 'description' => 'GAMCA / Approved clinic medical clearance report', 'is_mandatory' => true, 'validity_months' => 6, 'requires_expiry_date' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Police Clearance Certificate (PCC)', 'code' => 'POLICE_CLEARANCE', 'description' => 'Background check issued by home country authority', 'is_mandatory' => true, 'validity_months' => 12, 'requires_expiry_date' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Trade Skill Certificate', 'code' => 'SKILL_CERT', 'description' => 'Technical or trade assessment certification', 'is_mandatory' => false, 'validity_months' => 36, 'requires_expiry_date' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Employment Offer & Visa Stamping', 'code' => 'VISA_STAMP', 'description' => 'Work entry permit & visa stamp confirmation', 'is_mandatory' => true, 'validity_months' => 24, 'requires_expiry_date' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};