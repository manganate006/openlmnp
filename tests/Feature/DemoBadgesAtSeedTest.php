<?php

use App\Models\User;
use App\Models\UserBadge;
use App\Services\BadgeService;
use App\Services\DemoDataService;

// Les badges s'attribuent sur des ACTIONS, jamais sur l'état. Un bac à sable arrive
// donc rempli et sans badge, puis en décroche une pile au premier geste — le
// visiteur est félicité pour avoir ouvert le simulateur. Mesuré avant correction :
// 0 au seed, 10 d'un coup ensuite.

beforeEach(function () {
    // ⚠️ Les définitions de badges viennent d'un seeder, pas d'une migration :
    // sans cette ligne, `evaluateAll()` n'a rien à évaluer et le test mesure une
    // base vide au lieu du comportement. Mon premier jet est tombé dessus.
    $this->seed(\Database\Seeders\BadgeSeeder::class);
});

it('attribue les badges mérités dès la création du bac à sable', function () {
    $user = User::factory()->create(['is_demo' => true, 'demo_expires_at' => now()->addDay()]);

    app(DemoDataService::class)->seedForUser($user);

    $auSeed = UserBadge::where('user_id', $user->id)->count();

    expect($auSeed)->toBeGreaterThan(0);

    // ⚠️ L'assertion qui compte n'est pas « il y en a », c'est « il n'en reste
    // plus à décrocher sans rien faire ». Sans elle, calculer UN badge au seed
    // suffirait à passer, et la pluie au premier clic resterait.
    //
    // ⚠️ La sonde était `evaluate($user, 'simulator_used')`, et elle ne vaut plus :
    // passer ce déclencheur AFFIRME que le visiteur a utilisé le simulateur, donc le
    // badge se décerne — et c'est désormais correct. Elle ne marchait que parce que
    // `simulator_used` valait `true` SANS CONDITION, bug corrigé par le lot IA : trois
    // badges (`simulator_used`, `projection_used`, `ai_import_used`) ne laissent aucune
    // trace en base, seul le déclencheur les prouve.
    //
    // On sonde donc avec `evaluateAll()`, sans déclencheur — exactement ce que fait le
    // seed. C'est plus fort que la version d'origine : on vérifie à la fois que rien ne
    // reste à décrocher, ET qu'une réévaluation en bloc ne distribue aucun badge de
    // geste. C'est précisément ce que le bug faisait.
    $enPlus = app(BadgeService::class)->evaluateAll($user, silent: true);

    expect($enPlus)->toBe(0);
    expect(UserBadge::where('user_id', $user->id)->count())->toBe($auSeed);
});

it('attribue les badges du bac à sable en silence', function () {
    // ⚠️ Première version : `$user->notifications()->count()`. La table
    // `notifications` n'existe pas dans cette application — l'assertion levait une
    // erreur SQL au lieu de mesurer quoi que ce soit. On vérifie donc ce qui est
    // réellement observable : que le seed passe bien `silent`.
    //
    // Ces badges récompensent le jeu de données, pas un geste du visiteur : une
    // volée de félicitations à l'ouverture serait du bruit.
    $source = file_get_contents(app_path('Services/DemoDataService.php'));

    expect($source)->toContain('evaluateAll($user, silent: true)');
});
