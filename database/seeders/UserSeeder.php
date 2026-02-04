<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Admin User
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // Front Sale Users
        User::create([
            'name' => 'Front Sale User 1',
            'email' => 'frontsale1@example.com',
            'password' => Hash::make('password'),
            'role' => 'front_sale',
        ]);

        User::create([
            'name' => 'Front Sale User 2',
            'email' => 'frontsale2@example.com',
            'password' => Hash::make('password'),
            'role' => 'front_sale',
        ]);

        // Front Sale User
        User::create([
            'name' => 'Front Sale User',
            'email' => 'frontsale@example.com',
            'password' => Hash::make('password'),
            'role' => 'front_sale',
        ]);

        // Upsale User
        User::create([
            'name' => 'Upsale User',
            'email' => 'upsale@example.com',
            'password' => Hash::make('password'),
            'role' => 'upsale',
        ]);

        // Scrapper Users
        User::create([
            'name' => 'Scrapper User 1',
            'email' => 'scrapper1@example.com',
            'password' => Hash::make('password'),
            'role' => 'scrapper',
        ]);

        User::create([
            'name' => 'Scrapper User 2',
            'email' => 'scrapper2@example.com',
            'password' => Hash::make('password'),
            'role' => 'scrapper',
        ]);
    }
}
