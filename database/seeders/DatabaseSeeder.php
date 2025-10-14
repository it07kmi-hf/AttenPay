<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@attenpay.co.id'],
            ['name' => 'Admin', 'password' => Hash::make('attenpay')]
        );
    }
}
