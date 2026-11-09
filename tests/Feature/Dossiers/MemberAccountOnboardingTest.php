<?php

use App\Enums\DossierMemberRole;
use App\Filament\Widgets\OnboardingChecklist;
use App\Models\LinkedDossier;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Défaut gênant n°2 du test de bout en bout du 2026-10-15 : un compte membre voyait
 * encore « Créer votre bien — Commencer » dans le guide de démarrage alors que la
 * saisie d'un bien lui est fermée dans son propre principal (App\Support\
 * DossierContext::isMemberActingAsPrincipal()).
 *
 * `creerCompteMembre()`/`rattacherMembre()` : fonctions globales Pest, voir
 * tests/Feature/Dossiers/MemberAccountAccessTest.php. `creerDossierLie()` : voir
 * tests/Feature/Dossiers/LinkedDossierSwitchSecurityTest.php.
 */
beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

it('masque le guide de démarrage pour un compte membre dans son propre principal', function () {
    $membre = creerCompteMembre();
    Auth::login($membre);

    expect(OnboardingChecklist::canView())->toBeFalse();
});

it('réaffiche le guide de démarrage une fois le compte membre basculé DANS le dossier', function () {
    $proprietaire = User::factory()->create();
    $dossier = creerDossierLie($proprietaire);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);

    Auth::login($dossier->user);
    session([\App\Support\DossierContext::SESSION_PRINCIPAL_ID => $membre->id]);

    expect(OnboardingChecklist::canView())->toBeTrue();
});

it('un compte standard voit toujours le guide de démarrage', function () {
    $user = User::factory()->create();
    Auth::login($user);

    expect(OnboardingChecklist::canView())->toBeTrue();
});
