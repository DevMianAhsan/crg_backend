<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_types', 'priority')) {
            Schema::table('document_types', function (Blueprint $table): void {
                $table->unsignedInteger('priority')->default(100)->after('requires_expiry_date');
            });
        }

        // Set standard priorities for default types
        $priorityMap = [
            'PASSPORT' => 1,
            'PASSPORT_PAGE_2' => 2,
            'PASSPORT_BACK' => 2,
            'PHOTO' => 3,
            'PICTURE' => 3,
            'CANDIDATE_PHOTO' => 3,
            'PROFILE_PHOTO' => 3,
            'CNIC' => 4,
            'CNIC_FRONT' => 4,
            'CNIC_BACK' => 5,
            'NIC_BACK' => 5,
            'FRC' => 6,
            'FAMILY_REGISTRATION' => 6,
            'POLICE_CLEARANCE' => 7,
            'CHARACTER_CERTIFICATE' => 7,
            'MEDICAL_FITNESS' => 8,
            'GAMCA' => 8,
            'DRIVING_LICENSE' => 9,
            'SKILL_CERT' => 10,
            'TRADE_VIDEO' => 11,
            'VISA_STAMP' => 12,
            'OFFER_LETTER' => 13,
            'AGREEMENT' => 14,
            'CV' => 15,
        ];

        foreach ($priorityMap as $code => $prio) {
            DB::table('document_types')
                ->where('code', $code)
                ->orWhere('name', 'LIKE', "%{$code}%")
                ->update(['priority' => $prio]);
        }

        // Handle specific naming patterns
        DB::table('document_types')->where('name', 'LIKE', '%Passport%')->where('priority', 100)->update(['priority' => 1]);
        DB::table('document_types')->where('name', 'LIKE', '%Photo%')->orWhere('name', 'LIKE', '%Picture%')->where('priority', 100)->update(['priority' => 3]);
        DB::table('document_types')->where('name', 'LIKE', '%CNIC%')->where('priority', 100)->update(['priority' => 4]);
        DB::table('document_types')->where('name', 'LIKE', '%FRC%')->orWhere('name', 'LIKE', '%Family Registration%')->where('priority', 100)->update(['priority' => 6]);
        DB::table('document_types')->where('name', 'LIKE', '%Police%')->orWhere('name', 'LIKE', '%Character%')->where('priority', 100)->update(['priority' => 7]);
        DB::table('document_types')->where('name', 'LIKE', '%Medical%')->orWhere('name', 'LIKE', '%GAMCA%')->where('priority', 100)->update(['priority' => 8]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_types', 'priority')) {
            Schema::table('document_types', function (Blueprint $table): void {
                $table->dropColumn('priority');
            });
        }
    }
};
