<?php

use App\Filament\Pages\LinkedDossiers;
use App\Models\LinkedDossier;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Assistant de création en 3 étapes (écran 5, variante B, décision du 2026-10-15) et
 * action « Archiver » en menu + modale Filament (écran 4, variante C).
 *
 * ⚠️ `Livewire::test(...)->callAction(...)` REMPLIT directement l'état du
 * formulaire puis appelle le callback final de l'action — il ne simule PAS la
 * navigation pas à pas du Wizard (`nextStep()`/`afterValidation()` des étapes),
 * hors de portée du harnais de test Livewire (même limite déjà documentée dans
 * app/CLAUDE.md pour d'autres mécanismes de ce lot). C'est pourquoi ces tests
 * appellent `LinkedDossiers::inspectWizardSiren()` DIRECTEMENT (la méthode que
 * l'étape 1 invoque réellement) plutôt que de rejouer un clic sur « Vérifier dans
 * Sirene » : ils vérifient l'exacte logique qui alimente l'étape 2, pas le rendu
 * HTML du Wizard — vérifié au navigateur séparément (capture `5-assistant.png`).
 */
const WIZARD_SIREN = '100000009';

function wizardSireneCompany(array $overrides = []): array
{
    return array_replace([
        'siren' => WIZARD_SIREN,
        'nom_complet' => 'INDIVISION TEST WIZARD',
        'statut_diffusion' => 'O',
        'etat_administratif' => 'A',
        'date_creation' => '2020-01-01',
        'date_fermeture' => null,
        'activite_principale' => '68.20A',
        'nature_juridique' => '2110',
    ], $overrides);
}

function fakeWizardSirene(array $results): void
{
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::response([
            'results' => $results,
            'total_results' => count($results),
        ]),
    ]);
}

beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
    $this->owner = User::factory()->create();
});

it('étape 2 : résultat conforme, avec la fiche Sirene', function () {
    fakeWizardSirene([wizardSireneCompany()]);

    $component = Livewire::actingAs($this->owner)->test(LinkedDossiers::class);
    $component->call('inspectWizardSiren', WIZARD_SIREN);

    $check = $component->get('wizardCheck');

    expect($check['ok'])->toBeTrue()
        ->and($check['siren'])->toBe(WIZARD_SIREN)
        ->and($check['company']['name'])->toBe('INDIVISION TEST WIZARD')
        ->and($check['company']['legal_form'])->toBe('2110');
});

it('étape 2 : forme juridique autre que 2110 — refusé, PAS de lien support', function () {
    fakeWizardSirene([wizardSireneCompany(['nature_juridique' => '1000'])]);

    $component = Livewire::actingAs($this->owner)->test(LinkedDossiers::class);
    $component->call('inspectWizardSiren', WIZARD_SIREN);

    $check = $component->get('wizardCheck');

    expect($check['ok'])->toBeFalse()
        ->and($check['message'])->toContain('2110')
        ->and($check['supportable'])->toBeFalse()
        ->and($check['retryable'])->toBeFalse();
});

it('étape 2 : personne morale (2120) — refusé, AVEC lien support', function () {
    fakeWizardSirene([wizardSireneCompany(['nature_juridique' => '2120'])]);

    $component = Livewire::actingAs($this->owner)->test(LinkedDossiers::class);
    $component->call('inspectWizardSiren', WIZARD_SIREN);

    $check = $component->get('wizardCheck');

    expect($check['ok'])->toBeFalse()
        ->and($check['message'])->toContain('personne morale')
        ->and($check['supportable'])->toBeTrue()
        ->and($check['retryable'])->toBeFalse();
});

it('étape 2 : SIREN déjà suivi — message neutre, AVEC lien support', function () {
    fakeWizardSirene([wizardSireneCompany()]);
    app(\App\Services\LinkedDossierService::class)->create($this->owner, WIZARD_SIREN, 'Premier dossier');

    $autre = User::factory()->create();
    $component = Livewire::actingAs($autre)->test(LinkedDossiers::class);
    $component->call('inspectWizardSiren', WIZARD_SIREN);

    $check = $component->get('wizardCheck');

    expect($check['ok'])->toBeFalse()
        ->and($check['message'])->toContain('déjà suivi')
        ->and($check['supportable'])->toBeTrue();
});

it('étape 2 : Sirene indisponible — refusé, retryable, PAS de lien support', function () {
    Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response('', 500)]);

    $component = Livewire::actingAs($this->owner)->test(LinkedDossiers::class);
    $component->call('inspectWizardSiren', WIZARD_SIREN);

    $check = $component->get('wizardCheck');

    expect($check['ok'])->toBeFalse()
        ->and($check['retryable'])->toBeTrue()
        ->and($check['supportable'])->toBeFalse();
});

it('la création réelle revalide tout, indépendamment de l\'état affiché à l\'étape 2', function () {
    // Défense en profondeur : même si l'affichage de l'étape 2 (`wizardCheck`)
    // n'a jamais tourné (cas de `callAction()` en test, qui saute la navigation du
    // Wizard), la création finale appelle `LinkedDossierService::create()` et
    // revalide tout depuis zéro — elle ne fait JAMAIS confiance au seul état client.
    fakeWizardSirene([wizardSireneCompany()]);

    Livewire::actingAs($this->owner)
        ->test(LinkedDossiers::class)
        ->callAction('creer', ['siren' => WIZARD_SIREN, 'name' => 'Indivision Test'])
        ->assertHasNoActionErrors();

    expect(LinkedDossier::where('siren', WIZARD_SIREN)->exists())->toBeTrue();
});

it('archive un dossier via la modale Filament (archiverAction), menu réservé au responsable', function () {
    fakeWizardSirene([wizardSireneCompany()]);
    $dossier = app(\App\Services\LinkedDossierService::class)->create($this->owner, WIZARD_SIREN, 'Test');

    Livewire::actingAs($this->owner)
        ->test(LinkedDossiers::class)
        ->callAction('archiver', arguments: ['id' => $dossier->id])
        ->assertHasNoActionErrors();

    expect($dossier->fresh()->isArchived())->toBeTrue();
});

it('refuse d\'archiver via la modale pour qui n\'administre pas le dossier', function () {
    fakeWizardSirene([wizardSireneCompany()]);
    $dossier = app(\App\Services\LinkedDossierService::class)->create($this->owner, WIZARD_SIREN, 'Test');
    $etranger = User::factory()->create();

    try {
        Livewire::actingAs($etranger)
            ->test(LinkedDossiers::class)
            ->callAction('archiver', arguments: ['id' => $dossier->id]);
    } catch (\Throwable $e) {
        expect($e->getCode())->toBe(403);
    }

    expect($dossier->fresh()->isArchived())->toBeFalse();
});

// === Défaut gênant n°8 (2026-10-15) : nom proposé « INDIVISION » seul, inutile ===

it('extrait les dirigeants personnes physiques du registre pour un nom plus utile', function () {
    fakeWizardSirene([wizardSireneCompany([
        'nom_complet' => 'INDIVISION',
        'dirigeants' => [
            ['type_dirigeant' => 'personne physique', 'nom' => 'DUPONT', 'prenoms' => 'Jean'],
            ['type_dirigeant' => 'personne physique', 'nom' => 'MARTIN', 'prenoms' => 'Marie'],
            ['type_dirigeant' => 'personne morale', 'nom' => 'SCI SANS NOM'],
        ],
    ])]);

    $component = Livewire::actingAs($this->owner)->test(LinkedDossiers::class);
    $component->call('inspectWizardSiren', WIZARD_SIREN);

    expect($component->get('wizardCheck')['company']['leaders'])
        ->toBe(['Jean DUPONT', 'Marie MARTIN']);
});

it('propose un nom construit sur les dirigeants quand le registre ne nomme que « INDIVISION »', function () {
    $page = new LinkedDossiers;
    $method = new ReflectionMethod(LinkedDossiers::class, 'proposedDossierName');
    $method->setAccessible(true);

    $propose = fn (array $company) => $method->invoke($page, $company);

    expect($propose(['name' => 'INDIVISION', 'leaders' => ['Jean DUPONT', 'Marie MARTIN']]))
        ->toBe('Indivision Jean DUPONT et Marie MARTIN')
        ->and($propose(['name' => 'INDIVISION', 'leaders' => []]))
        ->toBe('')
        ->and($propose(['name' => 'SCI DES ERABLES', 'leaders' => []]))
        ->toBe('SCI DES ERABLES');
});

// === Défaut gênant n°7 (2026-10-15) : bouton Suivant actif malgré un SIREN refusé ===

it('désactive le bouton Suivant tant que la vérification Sirene échoue', function () {
    fakeWizardSirene([wizardSireneCompany(['nature_juridique' => '1000'])]);

    $component = Livewire::actingAs($this->owner)->test(LinkedDossiers::class);

    expect($component->instance()->wizardNextDisabled())->toBeFalse();

    $component->call('inspectWizardSiren', WIZARD_SIREN);

    expect($component->instance()->wizardNextDisabled())->toBeTrue();
});

it('réactive le bouton Suivant dès qu\'une vérification réussit', function () {
    // Deux SIREN distincts, répondus par UN SEUL `Http::fake()` (une fermeture
    // qui lit la query `q`) : un second `Http::fake()` sur le même hôte ne
    // remplace pas le premier, Laravel évalue les stubs dans l'ordre
    // d'enregistrement et le plus ancien continuerait de répondre.
    $autreSiren = '661234567';

    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => function ($request) use ($autreSiren) {
            $q = $request->data()['q'] ?? '';
            $company = $q === $autreSiren
                ? wizardSireneCompany(['siren' => $autreSiren])
                : wizardSireneCompany(['nature_juridique' => '1000']);

            return Http::response(['results' => [$company], 'total_results' => 1]);
        },
    ]);

    $component = Livewire::actingAs($this->owner)->test(LinkedDossiers::class);
    $component->call('inspectWizardSiren', WIZARD_SIREN);
    expect($component->instance()->wizardNextDisabled())->toBeTrue();

    $component->call('inspectWizardSiren', $autreSiren);
    expect($component->instance()->wizardNextDisabled())->toBeFalse();
});

// --- D2 du test bêta du 2026-10-15 : « Vérifier dans Sirene » de l'étape 1 répondait 500 ---

it('étape 1 : « Vérifier dans Sirene » affiche la fiche du SIREN saisi, sans erreur', function () {
    config(['opendata.sirene.enabled' => true]);
    \Illuminate\Support\Facades\RateLimiter::clear('sirene:'.$this->owner->id);
    // Cache disque de SireneClient : l'étape 1 relirait sinon la fiche d'un test précédent.
    \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/private/sirene'));
    fakeWizardSirene([wizardSireneCompany()]);

    // Avant le correctif : « Too few arguments to function LinkedDossiers::{closure}(),
    // 0 passed in SireneCheckAction.php » — la closure `fn (Get $get)` était appelée nue.
    Livewire::actingAs($this->owner)->test(LinkedDossiers::class)
        ->mountAction('creer')
        ->fillForm(['siren' => WIZARD_SIREN])
        ->mountAction(\Filament\Actions\Testing\TestAction::make('check_sirene')->schemaComponent('siren'))
        ->assertHasNoErrors()
        ->assertMountedActionModalSee('Entreprise active')
        ->assertMountedActionModalSee('INDIVISION TEST WIZARD');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'q='.WIZARD_SIREN));
});

// --- D4 du test bêta du 2026-10-15 : « Suivant » restait grisé après correction du SIREN ---
//
// Étapes de la bêta : SIREN valide mais pas 2110 → étape 2 « Refusé » → « Précédent » →
// saisie d'un SIREN conforme → « Suivant » toujours désactivé. `wizardCheck` n'était remis à
// `null` qu'à l'ouverture de la modale, et le champ SIREN n'était pas `live()` : rien ne
// prévenait le serveur que la saisie avait changé, donc rien ne réactivait le bouton.

it('modifier le SIREN après un refus réactive « Suivant »', function () {
    fakeWizardSirene([wizardSireneCompany(['nature_juridique' => '1000'])]);

    $component = Livewire::actingAs($this->owner)->test(LinkedDossiers::class)
        ->mountAction('creer')
        ->fillForm(['siren' => WIZARD_SIREN]);

    $component->call('inspectWizardSiren', WIZARD_SIREN);
    expect($component->instance()->wizardNextDisabled())->toBeTrue();

    // Retour à l'étape 1, correction du SIREN.
    $component->fillForm(['siren' => '661234567']);

    expect($component->get('wizardCheck'))->toBeNull()
        ->and($component->instance()->wizardNextDisabled())->toBeFalse();
});

it('le champ SIREN de l\'assistant est envoyé au serveur pendant la saisie (live)', function () {
    // Sans `live()`, la modification n'atteint le serveur qu'avec la requête SUIVANTE — celle
    // du clic sur « Suivant », précisément le bouton désactivé : le test ci-dessus passerait
    // (le harnais pousse l'état directement), mais le navigateur resterait bloqué.
    $component = Livewire::actingAs($this->owner)->test(LinkedDossiers::class)
        ->mountAction('creer');

    // La modale n'est pas dans le HTML de la page rendue par le harnais : on lit la
    // configuration du champ dans le schéma de l'action montée.
    $champ = $component->instance()->getSchema('mountedActionSchema0')->getComponent(
        fn ($c) => $c instanceof \Filament\Forms\Components\TextInput && $c->getName() === 'siren',
        withHidden: true,
    );

    expect($champ)->not->toBeNull()
        ->and($champ->isLive())->toBeTrue()
        ->and($champ->isLiveOnBlur())->toBeFalse();
});

it('Sirene indisponible : « Suivant » reste cliquable pour réessayer sans rien changer', function () {
    // Le message de l'étape 2 invite à « réessayer sans rien changer » depuis l'étape 1 :
    // un bouton désactivé l'interdisait.
    Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response('', 503)]);

    $component = Livewire::actingAs($this->owner)->test(LinkedDossiers::class);
    $component->call('inspectWizardSiren', WIZARD_SIREN);

    expect($component->get('wizardCheck')['retryable'])->toBeTrue()
        ->and($component->instance()->wizardNextDisabled())->toBeFalse();
});

// --- D5 du test bêta du 2026-10-15 : nom proposé « INDIVISION (INDIVISION) » ---
//
// Quand le sigle reprend la raison sociale, l'API renvoie `nom_complet` = « RAISON (SIGLE) » :
// « INDIVISION (INDIVISION) », format fréquent sur les 57 indivisions 2110 actives relevées.

it('propose un nom construit sur les dirigeants quand le registre dit « INDIVISION (INDIVISION) »', function () {
    $page = new LinkedDossiers;
    $method = new ReflectionMethod(LinkedDossiers::class, 'proposedDossierName');
    $propose = fn (array $company) => $method->invoke($page, $company);

    expect($propose(['name' => 'INDIVISION (INDIVISION)', 'leaders' => ['Jean DUPONT', 'Marie MARTIN']]))
        ->toBe('Indivision Jean DUPONT et Marie MARTIN')
        ->and($propose(['name' => 'Indivision ( indivision )', 'leaders' => ['Jean DUPONT']]))
        ->toBe('Indivision Jean DUPONT')
        ->and($propose(['name' => 'INDIVISION (INDIVISION)', 'leaders' => []]))
        ->toBe('')
        // Un sigle qui dit autre chose reste une information : on garde le nom du registre.
        ->and($propose(['name' => 'INDIVISION (DUPONT MARTIN)', 'leaders' => ['Jean DUPONT']]))
        ->toBe('INDIVISION (DUPONT MARTIN)');
});

it('la fiche Sirene ne répète pas un sigle identique à la raison sociale', function () {
    $fiche = \App\Services\OpenData\SireneClient::describe(wizardSireneCompany([
        'nom_complet' => 'INDIVISION (INDIVISION)',
        'nom_raison_sociale' => 'INDIVISION',
        'sigle' => 'INDIVISION',
    ]));

    expect($fiche['name'])->toBe('INDIVISION');

    $autre = \App\Services\OpenData\SireneClient::describe(wizardSireneCompany([
        'nom_complet' => 'SCI DES ERABLES (SDE)',
    ]));

    expect($autre['name'])->toBe('SCI DES ERABLES (SDE)');
});
