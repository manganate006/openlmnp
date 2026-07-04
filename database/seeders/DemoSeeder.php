<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\DemoDataService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seed le compte de démonstration fixe (demo@openlmnp.fr).
 *
 * La logique de création du jeu de données est déléguée au
 * DemoDataService, partagé avec le mode démo multi-utilisateurs.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => config('demo.email', 'demo@openlmnp.fr')],
            [
                'name' => 'Marie Dupont',
                'password' => Hash::make('demo2026'),
            ]
        );

        app(DemoDataService::class)->seedForUser($user);
    }
}
