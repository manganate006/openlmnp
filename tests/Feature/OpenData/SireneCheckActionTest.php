<?php

use App\Filament\Pages\EditProfile;
use App\Filament\Pages\Teledeclaration;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

const SIREN_PROFIL = '120027016'; // SIREN réel (Insee), passe la clé de Luhn.

beforeEach(function () {
    File::deleteDirectory(storage_path('app/private/sirene'));
    $this->user = User::factory()->create(['siren' => null]);
    RateLimiter::clear('sirene:'.$this->user->id);
    $this->actingAs($this->user);
});

function fakeSireneFor(string $siren, array $overrides = []): void
{
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::response([
            'results' => [array_replace([
                'siren' => $siren,
                'nom_complet' => 'SCI EXEMPLE',
                'statut_diffusion' => 'O',
                'etat_administratif' => 'A',
                'date_creation' => '2018-03-01',
                'date_fermeture' => null,
                'activite_principale' => '68.20A',
            ], $overrides)],
            'total_results' => 1,
        ]),
    ]);
}

// === Aucun appel sortant au simple rendu des pages ===

it('ne fait aucun appel réseau à la simple ouverture du profil', function () {
    $this->user->update(['siren' => SIREN_PROFIL]);
    Http::fake();

    $this->get('/profile')->assertOk();

    Http::assertNothingSent();
});

it('ne fait aucun appel réseau à la simple ouverture de la télédéclaration', function () {
    $this->user->update(['siren' => SIREN_PROFIL]);
    Http::fake();

    $this->get('/teledeclaration')->assertOk();

    Http::assertNothingSent();
});

// === Visibilité ===

it('propose le bouton à côté du champ SIREN du profil', function () {
    $this->get('/profile')->assertOk()->assertSee('check_sirene');
});

it('retire entièrement le bouton du profil quand Sirene est désactivée', function () {
    config(['opendata.sirene.enabled' => false]);

    Livewire::test(EditProfile::class)
        ->assertActionDoesNotExist(TestAction::make('check_sirene')->schemaComponent('siren'));
});

it('ne propose pas le bouton en télédéclaration tant qu\'aucun SIREN n\'est renseigné', function () {
    Livewire::test(Teledeclaration::class)
        ->assertActionHidden('check_sirene');
});

it('propose le bouton en télédéclaration dès qu\'un SIREN est renseigné', function () {
    $this->user->update(['siren' => SIREN_PROFIL]);

    Livewire::test(Teledeclaration::class)
        ->assertActionVisible('check_sirene');
});

it('retire le bouton de télédéclaration quand Sirene est désactivée, même avec un SIREN', function () {
    $this->user->update(['siren' => SIREN_PROFIL]);
    config(['opendata.sirene.enabled' => false]);

    Livewire::test(Teledeclaration::class)
        ->assertActionHidden('check_sirene');
});

// === Contenu de la vérification, depuis le profil ===

it('vérifie le SIREN fraîchement saisi, pas seulement celui déjà enregistré', function () {
    fakeSireneFor(SIREN_PROFIL, ['activite_principale' => '55.20Z']);

    Livewire::test(EditProfile::class)
        ->fillForm(['siren' => SIREN_PROFIL])
        ->mountAction(TestAction::make('check_sirene')->schemaComponent('siren'))
        ->assertMountedActionModalSee('Entreprise active')
        ->assertMountedActionModalSee('55.20Z')
        ->assertMountedActionModalSee('Hébergement touristique');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'q='.SIREN_PROFIL));
});

it('avertit sans accuser quand le code APE n\'est ni 68.20A ni 55.20Z', function () {
    $this->user->update(['siren' => SIREN_PROFIL]);
    fakeSireneFor(SIREN_PROFIL, ['activite_principale' => '47.11Z']);

    Livewire::test(EditProfile::class)
        ->mountAction(TestAction::make('check_sirene')->schemaComponent('siren'))
        ->assertMountedActionModalSee('47.11Z')
        ->assertMountedActionModalSee('mérite une vérification');
});

it('avertit quand l\'entreprise est déclarée cessée', function () {
    $this->user->update(['siren' => SIREN_PROFIL]);
    fakeSireneFor(SIREN_PROFIL, ['etat_administratif' => 'C', 'date_fermeture' => '2024-11-30']);

    Livewire::test(EditProfile::class)
        ->mountAction(TestAction::make('check_sirene')->schemaComponent('siren'))
        ->assertMountedActionModalSee('cessée')
        ->assertMountedActionModalSee('mérite une vérification');
});

it('explique l\'absence de SIREN enregistré sans lancer de requête', function () {
    Http::fake();

    Livewire::test(EditProfile::class)
        ->mountAction(TestAction::make('check_sirene')->schemaComponent('siren'))
        ->assertMountedActionModalSee('Aucun SIREN enregistré');

    Http::assertNothingSent();
});

it('refuse de vérifier un SIREN dont la clé de Luhn est fausse, sans lancer de requête', function () {
    Http::fake();

    Livewire::test(EditProfile::class)
        ->fillForm(['siren' => '123456789'])
        ->mountAction(TestAction::make('check_sirene')->schemaComponent('siren'))
        ->assertMountedActionModalSee('Luhn');

    Http::assertNothingSent();
});

// === Contenu de la vérification, depuis la télédéclaration ===

it('vérifie le SIREN du profil depuis l\'écran de télédéclaration', function () {
    $this->user->update(['siren' => SIREN_PROFIL]);
    fakeSireneFor(SIREN_PROFIL);

    Livewire::test(Teledeclaration::class)
        ->mountAction(TestAction::make('check_sirene'))
        ->assertMountedActionModalSee('Entreprise active')
        ->assertMountedActionModalSee('68.20A');
});

// === Validation du champ, dans le profil ===

it('refuse d\'enregistrer un SIREN dont la clé de Luhn est fausse', function () {
    Livewire::test(EditProfile::class)
        ->fillForm(['siren' => '123456789'])
        ->call('save')
        ->assertHasFormErrors(['siren']);

    expect($this->user->fresh()->siren)->toBeNull();
});

it('refuse d\'enregistrer un SIREN qui ne compte pas 9 chiffres', function () {
    Livewire::test(EditProfile::class)
        ->fillForm(['siren' => '1234'])
        ->call('save')
        ->assertHasFormErrors(['siren']);
});

it('enregistre un SIREN valide en retirant les espaces de séparation', function () {
    Livewire::test(EditProfile::class)
        ->fillForm(['siren' => '120 027 016', 'timezone' => 'Europe/Paris'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->siren)->toBe(SIREN_PROFIL);
});

it('laisse le SIREN vide, le champ étant optionnel', function () {
    Livewire::test(EditProfile::class)
        ->fillForm(['siren' => '', 'timezone' => 'Europe/Paris'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->siren)->toBeNull();
});

// === Forme juridique et dossier d'indivision ===

it('affiche la forme juridique d\'une indivision sans avertir un dossier déclaré comme tel', function () {
    $this->user->update(['siren' => SIREN_PROFIL, 'is_indivision' => true]);
    fakeSireneFor(SIREN_PROFIL, ['nature_juridique' => '2110']);

    Livewire::test(EditProfile::class)
        ->mountAction(TestAction::make('check_sirene')->schemaComponent('siren'))
        ->assertMountedActionModalSee('Indivision entre personnes physiques')
        ->assertMountedActionModalDontSee('pas celui');
});

it('avertit quand un dossier en indivision porte le SIREN personnel d\'un indivisaire', function () {
    $this->user->update(['siren' => SIREN_PROFIL, 'is_indivision' => true]);
    fakeSireneFor(SIREN_PROFIL, ['nature_juridique' => '1000']);

    Livewire::test(EditProfile::class)
        ->mountAction(TestAction::make('check_sirene')->schemaComponent('siren'))
        ->assertMountedActionModalSee('Entrepreneur individuel')
        ->assertMountedActionModalSee('pas celui');
});

it('suit l\'interrupteur Indivision du formulaire en cours, pas seulement la valeur enregistrée', function () {
    $this->user->update(['siren' => SIREN_PROFIL, 'is_indivision' => false]);
    fakeSireneFor(SIREN_PROFIL, ['nature_juridique' => '1000']);

    Livewire::test(EditProfile::class)
        ->fillForm(['is_indivision' => true])
        ->mountAction(TestAction::make('check_sirene')->schemaComponent('siren'))
        ->assertMountedActionModalSee('pas celui');
});

it('suggère la rubrique Indivision quand le SIREN est celui d\'une indivision', function () {
    $this->user->update(['siren' => SIREN_PROFIL]);
    fakeSireneFor(SIREN_PROFIL, ['nature_juridique' => '2110']);

    Livewire::test(EditProfile::class)
        ->mountAction(TestAction::make('check_sirene')->schemaComponent('siren'))
        ->assertMountedActionModalSee('activez la rubrique');
});
