<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

// Une instance fraîchement installée n'a AUCUN compte : le seeder ne pose que les données de
// référence, et c'est le premier visiteur qui s'inscrit qui devient administrateur
// (ALLOW_REGISTRATION=auto referme ensuite l'inscription).
//
// Ce que ce test protège : la documentation d'installation et le script LXC annonçaient un
// compte « demo@openlmnp.fr / demo2026 » qui n'existe que dans le mode démonstration. Le jour
// où un compte serait ajouté au seeder, l'inscription se trouverait fermée d'entrée de jeu et
// personne ne pourrait plus entrer.

it('seeds a fresh instance without creating any account', function () {
    config(['demo.enabled' => false]);

    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(0);
});
