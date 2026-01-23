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

        // Sales Users
        User::create([
            'name' => 'Sales User 1',
            'email' => 'sales1@example.com',
            'password' => Hash::make('password'),
            'role' => 'sales',
        ]);

        User::create([
            'name' => 'Sales User 2',
            'email' => 'sales2@example.com',
            'password' => Hash::make('password'),
            'role' => 'sales',
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
