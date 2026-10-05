<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
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
                'is_mandatory' => false,
                'validity_months' => 120,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'Character Certificate / Police Clearance',
                'code' => 'POLICE_CLEARANCE',
                'description' => 'Police character certificate / background clearance issued by authority',
                'is_mandatory' => false,
                'validity_months' => 6,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'WORKING VIDEO',
                'code' => 'TRADE_VIDEO',
                'description' => 'Recorded video demonstrating candidate practical trade skill work or interview',
                'is_mandatory' => false,
                'validity_months' => 36,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'DRIVING LICENCE',
                'code' => 'DRIVING_LICENSE',
                'description' => 'Official national or international motor vehicle driving license',
                'is_mandatory' => false,
                'validity_months' => 0,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'OFFER LETTER',
                'code' => 'VISA_STAMP',
                'description' => 'Work entry permit & visa stamp confirmation',
                'is_mandatory' => false,
                'validity_months' => 24,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'Other Compliance Document',
                'code' => 'OTHER',
                'description' => 'Other supporting documents, affidavits, certificates, or media files',
                'is_mandatory' => false,
                'validity_months' => 12,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'Work Permit',
                'code' => 'WORK_PERMIT',
                'description' => 'Work permit, labor permit, or entry permit document',
                'is_mandatory' => false,
                'validity_months' => 24,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'PICTURE',
                'code' => 'PICTURE',
                'description' => null,
                'is_mandatory' => false,
                'validity_months' => 12,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'CV',
                'code' => 'CV',
                'description' => null,
                'is_mandatory' => false,
                'validity_months' => 12,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'EDUCATION CERTIFICATE',
                'code' => 'EDUCATION_CERTIFICATE',
                'description' => null,
                'is_mandatory' => false,
                'validity_months' => 12,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'EXPERIENCE CERTIFICATE',
                'code' => 'EXPERIENCE_CERTIFICATE',
                'description' => null,
                'is_mandatory' => false,
                'validity_months' => 12,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'FRC',
                'code' => 'FRC',
                'description' => null,
                'is_mandatory' => false,
                'validity_months' => 12,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'POLIO CARD',
                'code' => 'POLIO_CARD',
                'description' => null,
                'is_mandatory' => false,
                'validity_months' => 12,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'AFFIDAVIT',
                'code' => 'AFFIDAVIT',
                'description' => null,
                'is_mandatory' => false,
                'validity_months' => 12,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'VISA STICKER',
                'code' => 'VISA_STICKER',
                'description' => null,
                'is_mandatory' => false,
                'validity_months' => 12,
                'requires_expiry_date' => false,
            ],
            [
                'name' => 'PASSPORT 2ND PAGE',
                'code' => 'PASSPORT_2ND_PAGE',
                'description' => null,
                'is_mandatory' => false,
                'validity_months' => 12,
                'requires_expiry_date' => false,
            ],
        ];

        foreach ($types as $type) {
            DocumentType::updateOrCreate(
                ['code' => $type['code']],
                $type
            );
        }
    }
}
