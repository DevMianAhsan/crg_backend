<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $types = [
            [
                'name' => 'Passport',
                'code' => 'PASSPORT',
                'description' => 'International travel passport with at least 6 months validity',
                'is_mandatory' => true,
                'validity_months' => 60,
                'requires_expiry_date' => true,
            ],
            [
                'name' => 'CNIC / National Identity Card',
                'code' => 'CNIC',
                'description' => 'National ID card (Computerized National Identity Card / Smart Card)',
                'is_mandatory' => true,
                'validity_months' => 120,
                'requires_expiry_date' => true,
            ],
            [
                'name' => 'Character Certificate / Police Clearance',
                'code' => 'POLICE_CLEARANCE',
                'description' => 'Police character certificate / background clearance issued by authority',
                'is_mandatory' => true,
                'validity_months' => 12,
                'requires_expiry_date' => true,
            ],
            [
                'name' => 'Medical Fitness Certificate',
                'code' => 'MEDICAL_FITNESS',
                'description' => 'GAMCA / Approved clinic medical clearance report',
                'is_mandatory' => true,
                'validity_months' => 6,
                'requires_expiry_date' => true,
            ],
            [
                'name' => 'Trade Skill Certificate',
                'code' => 'SKILL_CERT',
                'description' => 'Technical or trade assessment certification',
                'is_mandatory' => false,
                'validity_months' => 36,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'Trade / Skill Video Clip',
                'code' => 'TRADE_VIDEO',
                'description' => 'Recorded video demonstrating candidate practical trade skill work or interview',
                'is_mandatory' => false,
                'validity_months' => 36,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'Driving License',
                'code' => 'DRIVING_LICENSE',
                'description' => 'Official national or international motor vehicle driving license',
                'is_mandatory' => false,
                'validity_months' => 60,
                'requires_expiry_date' => true,
            ],
            [
                'name' => 'Employment Offer & Visa Stamping',
                'code' => 'VISA_STAMP',
                'description' => 'Work entry permit & visa stamp confirmation',
                'is_mandatory' => true,
                'validity_months' => 24,
                'requires_expiry_date' => true,
            ],
            [
                'name' => 'Other Compliance Document',
                'code' => 'OTHER',
                'description' => 'Other supporting documents, affidavits, certificates, or media files',
                'is_mandatory' => false,
                'validity_months' => 12,
                'requires_expiry_date' => false,
            ],
        ];

        foreach ($types as $item) {
            $existing = DB::table('document_types')->where('code', $item['code'])->first();
            if ($existing) {
                DB::table('document_types')->where('id', $existing->id)->update([
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'requires_expiry_date' => $item['requires_expiry_date'],
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('document_types')->insert(array_merge($item, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        // No-op rollback
    }
};
