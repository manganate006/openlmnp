<?php

use App\Filament\Pages\EditProfile;
use App\Models\User;
use Livewire\Livewire;

/**
 * Défaut gênant n°4 du test de bout en bout (2026-10-15) : le profil d'un dossier
 * d'indivision tout juste créé s'ouvrait avec le Repeater « Indivisaires » VIDE —
 * `defaultItems(2)` ne joue jamais sur cette page, toujours en mode édition.
 *
 * `creerDossierLie()` : fonction globale Pest, voir LinkedDossierSwitchSecurityTest.php.
 *
 * ⚠️ Le Repeater de Filament indexe son état par des clés UUID générées à
 * l'hydratation, jamais 0/1 : on compare donc `array_values()` de l'état, pas le
 * tableau brut (`assertFormSet` échouerait sur les clés, pas sur le contenu).
 */
beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

it('arrive avec deux lignes vides quand Sirene ne donne aucun dirigeant', function () {
    $proprietaire = User::factory()->create();
    $dossier = creerDossierLie($proprietaire);

    $this->actingAs($dossier->user);

    $component = Livewire::test(EditProfile::class);

    expect(array_values($component->get('data.co_owners')))->toBe([
        ['name' => null, 'share' => null, 'address' => null, 'birth' => null, 'tax_id' => null],
        ['name' => null, 'share' => null, 'address' => null, 'birth' => null, 'tax_id' => null],
    ]);
});

it('propose les indivisaires trouvés par Sirene plutôt que des lignes vides', function () {
    $proprietaire = User::factory()->create();
    $dossier = creerDossierLie($proprietaire);
    $dossier->update(['sirene_snapshot' => ['leaders' => ['Jean DUPONT', 'Marie MARTIN']]]);

    $this->actingAs($dossier->user);

    $component = Livewire::test(EditProfile::class);

    expect(array_values($component->get('data.co_owners')))->toBe([
        ['name' => 'Jean DUPONT', 'share' => null, 'address' => null, 'birth' => null, 'tax_id' => null],
        ['name' => 'Marie MARTIN', 'share' => null, 'address' => null, 'birth' => null, 'tax_id' => null],
    ]);
});

it('ne touche jamais des indivisaires déjà saisis', function () {
    $proprietaire = User::factory()->create();
    $dossier = creerDossierLie($proprietaire);
    $dossier->user->update(['co_owners' => [
        ['name' => 'Déjà saisi', 'share_bp' => 10000],
    ]]);

    $this->actingAs($dossier->user->fresh());

    $component = Livewire::test(EditProfile::class);

    expect(array_values($component->get('data.co_owners')))->toBe([
        ['name' => 'Déjà saisi', 'share' => '100', 'address' => null, 'birth' => null, 'tax_id' => null],
    ]);
});
