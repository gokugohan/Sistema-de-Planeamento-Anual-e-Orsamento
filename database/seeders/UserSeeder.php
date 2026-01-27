<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Directorate;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@goku.tl'],
            [
                'name' => 'Administrador do Sistema',
                'password' => Hash::make('password'),
            ]
        );
        $admin->syncRoles('Admin');

        // Minister
        $minister = User::firstOrCreate(
            ['email' => 'ministro@goku.tl'],
            [
                'name' => 'Ministro da Administração',
                'password' => Hash::make('password'),
            ]
        );
        $minister->syncRoles('Minister');

        // Directorates Users
        $directorates = Directorate::all();

        foreach ($directorates as $dir) {
            // Planner
            $planner = User::firstOrCreate(
                ['email' => "planner." . strtolower($dir->code) . "@goku.tl"],
                [
                    'name' => "Planner " . $dir->code,
                    'password' => Hash::make('password'),
                    'directorate_id' => $dir->id,
                ]
            );
            $planner->syncRoles('Planning');

            // Director
            $director = User::firstOrCreate(
                ['email' => "director." . strtolower($dir->code) . "@goku.tl"],
                [
                    'name' => "Director " . $dir->code,
                    'password' => Hash::make('password'),
                    'directorate_id' => $dir->id,
                ]
            );
            $director->syncRoles('Director');
        }
    }
}
