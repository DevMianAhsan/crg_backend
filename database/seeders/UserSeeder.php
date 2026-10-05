<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Super Admin',
                'email' => 'admin@gmail.com',
                'password' => Hash::make('12345678'),
                'role' => 'super_admin',
                'status' => 'active',
                'permissions' => null,
            ],
            [
                'name' => 'salman',
                'email' => 'salman@gmail.com',
                'password' => Hash::make('12345678'),
                'role' => 'recruiter',
                'status' => 'active',
                'permissions' => [
                    'candidates.view',
                    'candidates.update',
                    'documents.view',
                    'documents.create',
                    'documents.update',
                    'documents.verify',
                ],
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                $userData
            );
        }
    }
}
