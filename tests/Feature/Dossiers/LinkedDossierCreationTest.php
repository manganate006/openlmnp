<?php

use App\Models\LinkedDossier;
use App\Models\User;
use App\Services\LinkedDossierCreationException;
use App\Services\LinkedDossierService;
use Illuminate\Support\Facades\Http;

/**
 * `LinkedDossierService::create()` — toute la logique d'autorisation de la création
 * d'un dossier lié (plan des dossiers liés § 1, § 4, décision n°3/n°4).
 */
const SIREN_INDIVISION_1 = '100000009';
const SIREN_INDIVISION_2 = '100000017';

/** @return array<string, mixed> */
function dossierSireneCompany(array $overrides = []): array
{
    return array_replace([
        'siren' => SIREN_INDIVISION_1,
        'nom_complet' => 'INDIVISION DUPONT MARTIN',
        'statut_diffusion' => 'O',
        'etat_administratif' => 'A',
        'date_creation' => '2020-01-01',
        'date_fermeture' => null,
        'activite_principale' => '68.20A',
        'nature_juridique' => '2110',
    ], $overrides);
}

function fakeDossierSirene(array $results): void
{
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::response([
            'results' => $results,
            'total_results' => count($results),
        ]),
    ]);
}

beforeEach(function () {
    // ⚠️ Remise à plat EXPLICITE : certains environnements d'exécution portent un
    // PROVISION_TOKEN réel dans leur environnement système (hérité du processus),
    // ce qui ferait passer ces tests pour « le cloud » sans qu'aucune ligne du test
    // ne le dise. DossierQuotaTest fait la même remise à plat.
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
    $this->owner = User::factory()->create();
});

it('crée un dossier lié pour un SIREN d\'indivision vérifié (2110, actif)', function () {
    fakeDossierSirene([dossierSireneCompany()]);

    $dossier = app(LinkedDossierService::class)->create($this->owner, SIREN_INDIVISION_1, 'Indivision Dupont-Martin');

    expect($dossier)->toBeInstanceOf(LinkedDossier::class)
        ->and($dossier->siren)->toBe(SIREN_INDIVISION_1)
        ->and($dossier->owner_id)->toBe($this->owner->id)
        ->and($dossier->sirene_snapshot)->not->toBeNull();

    $dossierUser = $dossier->user;
    expect($dossierUser->is_linked_dossier)->toBeTrue()
        ->and($dossierUser->is_indivision)->toBeTrue()
        ->and($dossierUser->is_admin)->toBeFalse()
        ->and($dossierUser->siren)->toBe(SIREN_INDIVISION_1)
        ->and($dossierUser->email)->toEndWith('@dossiers.invalid')
        ->and($dossierUser->routeNotificationForMail())->toBeNull();

    expect($dossier->members()->where('user_id', $this->owner->id)->whereNotNull('accepted_at')->exists())->toBeTrue();
});

it('refuse un SIREN invalide (clé de Luhn)', function () {
    expect(fn () => app(LinkedDossierService::class)->create($this->owner, '123456789', 'Test'))
        ->toThrow(LinkedDossierCreationException::class, 'Luhn');

    Http::fake();
    Http::assertNothingSent();
});

it('refuse un SIREN déjà suivi sur l\'instance, par un message neutre', function () {
    fakeDossierSirene([dossierSireneCompany()]);
    app(LinkedDossierService::class)->create($this->owner, SIREN_INDIVISION_1, 'Premier dossier');

    $autre = User::factory()->create();

    expect(fn () => app(LinkedDossierService::class)->create($autre, SIREN_INDIVISION_1, 'Second dossier'))
        ->toThrow(LinkedDossierCreationException::class, 'déjà suivi');

    expect(LinkedDossier::count())->toBe(1);
});

it('refuse une nature juridique autre que 2110 (2120 oriente vers le support)', function () {
    fakeDossierSirene([dossierSireneCompany(['nature_juridique' => '2120'])]);

    expect(fn () => app(LinkedDossierService::class)->create($this->owner, SIREN_INDIVISION_1, 'Test'))
        ->toThrow(LinkedDossierCreationException::class, 'personne morale');

    expect(LinkedDossier::count())->toBe(0);
});

it('refuse une nature juridique qui n\'est pas une indivision', function () {
    fakeDossierSirene([dossierSireneCompany(['nature_juridique' => '1000'])]);

    expect(fn () => app(LinkedDossierService::class)->create($this->owner, SIREN_INDIVISION_1, 'Test'))
        ->toThrow(LinkedDossierCreationException::class, '2110');
});

it('refuse une entreprise cessée', function () {
    fakeDossierSirene([dossierSireneCompany(['etat_administratif' => 'C', 'date_fermeture' => '2025-01-01'])]);

    expect(fn () => app(LinkedDossierService::class)->create($this->owner, SIREN_INDIVISION_1, 'Test'))
        ->toThrow(LinkedDossierCreationException::class, 'cessée');
});

it('refuse un SIREN introuvable dans la base Sirene', function () {
    fakeDossierSirene([]);

    expect(fn () => app(LinkedDossierService::class)->create($this->owner, SIREN_INDIVISION_1, 'Test'))
        ->toThrow(LinkedDossierCreationException::class, 'Aucune entreprise');
});

it('répercute l\'indisponibilité de Sirene sans créer de dossier', function () {
    Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response('', 500)]);

    expect(fn () => app(LinkedDossierService::class)->create($this->owner, SIREN_INDIVISION_1, 'Test'))
        ->toThrow(LinkedDossierCreationException::class);

    expect(LinkedDossier::count())->toBe(0);
});

it('refuse la création pour un compte de démonstration', function () {
    $demo = User::factory()->create(['is_demo' => true]);

    expect(fn () => app(LinkedDossierService::class)->create($demo, SIREN_INDIVISION_1, 'Test'))
        ->toThrow(LinkedDossierCreationException::class, 'démonstration');

    Http::fake();
    Http::assertNothingSent();
});

it('refuse la création au-delà du quota', function () {
    $this->owner->forceFill(['linked_dossier_quota' => 1])->save();
    fakeDossierSirene([dossierSireneCompany()]);
    app(LinkedDossierService::class)->create($this->owner, SIREN_INDIVISION_1, 'Premier');

    fakeDossierSirene([dossierSireneCompany(['siren' => SIREN_INDIVISION_2])]);

    expect(fn () => app(LinkedDossierService::class)->create($this->owner, SIREN_INDIVISION_2, 'Second'))
        ->toThrow(LinkedDossierCreationException::class, 'nombre de dossiers liés offerts');

    expect(LinkedDossier::count())->toBe(1);
});

it('rend la vérification Sirene facultative en auto-hébergement si l\'open data est coupé', function () {
    config(['opendata.sirene.enabled' => false]);
    Http::fake();

    $dossier = app(LinkedDossierService::class)->create($this->owner, SIREN_INDIVISION_1, 'Sans Sirene');

    expect($dossier->sirene_snapshot)->toBeNull()
        ->and($dossier->sirene_checked_at)->toBeNull();

    Http::assertNothingSent();
});

it('refuse la création sur le cloud si l\'open data Sirene est coupé', function () {
    config(['opendata.sirene.enabled' => false, 'services.provisioning.token' => 'secret-cloud']);

    expect(fn () => app(LinkedDossierService::class)->create($this->owner, SIREN_INDIVISION_1, 'Test'))
        ->toThrow(LinkedDossierCreationException::class);

    expect(LinkedDossier::count())->toBe(0);
});

// === Contre-épreuve : la garde anti-détournement (SIREN+nature juridique) retire bien
// le dossier créé. Retirer la garde (commentée ci-dessous dans LinkedDossierService::
// verifySirene()) ferait passer ce test au vert pour la MAUVAISE raison : il faut le
// voir échouer pour la garde en place, ce que l'assertion ci-dessus fait déjà (le test
// « refuse une nature juridique qui n'est pas une indivision » échoue immédiatement si
// la condition `$described['legal_form'] !== '2110'` est retirée du service).
