<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            [
                'name' => 'UAE',
                'code' => 'AE',
                'phone_code' => '971',
                'is_active' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Qatar',
                'code' => 'QA',
                'phone_code' => '974',
                'is_active' => false,
                'sort_order' => 3,
            ],
            [
                'name' => 'Kuwait',
                'code' => 'KW',
                'phone_code' => '965',
                'is_active' => false,
                'sort_order' => 4,
            ],
            [
                'name' => 'Bahrain',
                'code' => 'BH',
                'phone_code' => '973',
                'is_active' => false,
                'sort_order' => 5,
            ],
            [
                'name' => 'Bulgaria',
                'code' => 'BG',
                'phone_code' => '359',
                'is_active' => true,
                'sort_order' => 6,
            ],
            [
                'name' => 'Czech Republic',
                'code' => 'CZ',
                'phone_code' => '420',
                'is_active' => true,
                'sort_order' => 7,
            ],
            [
                'name' => 'Romania',
                'code' => 'RO',
                'phone_code' => '40',
                'is_active' => true,
                'sort_order' => 8,
            ],
            [
                'name' => 'Other',
                'code' => 'OTHER',
                'phone_code' => null,
                'is_active' => false,
                'sort_order' => 99,
            ],
            [
                'name' => 'SERBIA',
                'code' => null,
                'phone_code' => null,
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'name' => 'ESTONIA',
                'code' => null,
                'phone_code' => null,
                'is_active' => true,
                'sort_order' => 0,
            ],
        ];

        foreach ($countries as $country) {
            Country::updateOrCreate(
                ['name' => $country['name']],
                $country
            );
        }
    }
}
