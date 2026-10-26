<?php

use App\Http\Controllers\DemoLoginController;
use App\Http\Controllers\DemoResumeController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Dossier\AcceptDossierInvitationController;
use App\Http\Controllers\Dossier\CreateMemberAccountController;
use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

// ⚠️ `signed:raw` — le paramètre `raw` est IGNORÉ de la vérification de signature,
// pas dispensé d'authentification : `path`/`expires` continuent de la porter, et
// c'est le middleware `signed` lui-même qui les vérifie. Sans cet ignore, ajouter
// `?raw=1` à une URL déjà signée (ce que fait `vault.js#chiffre()` pour récupérer
// le chiffré brut) invalide la signature — Laravel la recalcule sur la QUERY
// STRING ACTUELLE de la requête, pas sur celle qui a été signée à l'origine. Le
// lien produit par `DocumentStorage::vaultAwareFileUrl()` en dépend directement.
Route::get('/d/{path}', DocumentController::class)
    ->where('path', '.*')
    ->middleware(['signed:raw', \App\Http\Middleware\EnsureDossierContext::class])
    ->name('documents.show');

// Matériel de clé du coffre-fort, pour un déchiffrement côté navigateur. Rien de
// secret n'y transite : la clé privée y est scellée, et l'ouvrir demande la phrase
// de passe, qui ne quitte jamais le poste.
Route::get('/vault/key-material', \App\Http\Controllers\Vault\VaultKeyMaterialController::class)
    ->middleware(['auth', \App\Http\Middleware\EnsureDossierContext::class, 'throttle:30,1'])
    ->name('vault.key-material');

// Lien d'invitation. Route AUTONOME, hors du panel : `AdminPanelProvider` injecte GTM sur
// toutes les pages du panel, et GA4 envoie `page_location` avec la query string — un jeton
// passé en paramètre partirait chez Google à chaque ouverture. Ici le jeton est un SEGMENT
// d'URL, et le contrôleur le transforme aussitôt en entrée de session.
//
// Pas de `signed` : le secret EST dans l'URL, et la révocation se vérifie en base.
Route::get('/invitation/{token}', InvitationController::class)
    ->middleware('throttle:10,1')
    ->name('invitation.show');

// Lien d'invitation à un dossier lié EXISTANT (second indivisaire, lot 3). Même
// raisonnement que la route ci-dessus (jeton en SEGMENT, hors panel, pas de
// GTM). ⚠️ `abort_unless(config('dossiers.enabled'), 404)` est DANS le
// contrôleur, pas ici : la coupure d'une route toujours enregistrée doit se
// faire au même endroit que les autres routes « dossiers » (voir
// AdminPanelProvider pour l'explication détaillée du même piège).
Route::get('/dossiers/invitation/{token}', [AcceptDossierInvitationController::class, 'show'])
    ->middleware('throttle:10,1')
    ->name('dossier.invitation.show');

// Rattachement effectif — POST STRICTEMENT (écran 7, variante B, décision du
// 2026-10-15) : le GET ci-dessus n'affiche plus que l'invitation, sans AUCUNE
// mutation ; c'est cette route qui rattache réellement, sur confirmation EXPLICITE
// de l'invité. Protégée par le même jeton (en segment) ET par le CSRF (formulaire
// POST classique, cookie de session) — un lien suivi au survol, ou rejoué par un
// robot de prévisualisation de courriel, ne rattache donc jamais rien.
Route::post('/dossiers/invitation/{token}/confirmer', [AcceptDossierInvitationController::class, 'confirm'])
    ->middleware('throttle:10,1')
    ->name('dossier.invitation.confirm');

// Refus explicite de l'invitation (écran 7, variante B) : révoque le lien du point
// de vue de l'invité — même état qu'une révocation par l'invitant (« Révoquée »),
// pour qu'il ne reste pas éternellement « En attente » dans la liste du dossier.
Route::post('/dossiers/invitation/{token}/refuser', [AcceptDossierInvitationController::class, 'decline'])
    ->middleware('throttle:10,1')
    ->name('dossier.invitation.decline');

// Cas « mauvais compte » (écran 7, variante B) : un seul bouton qui déconnecte PUIS
// ramène sur ce même lien — jamais un simple lien de connexion qui laisserait la
// mauvaise session active.
Route::post('/dossiers/invitation/{token}/changer-de-compte', [AcceptDossierInvitationController::class, 'switchAccount'])
    ->middleware('throttle:10,1')
    ->name('dossier.invitation.switch-account');

// Création d'un compte « membre » gratuit depuis CE MÊME lien (lot « dossiers
// membre », décision du 2026-10-15) : POST STRICTEMENT, même raison que la bascule
// de dossier (App\Http\Controllers\Dossier\OpenDossierController) — un formulaire,
// jamais un lien suivi au survol.
Route::post('/dossiers/invitation/{token}/compte', CreateMemberAccountController::class)
    ->middleware('throttle:10,1')
    ->name('dossier.invitation.create-member');

// Autocomplétion d'adresse par la Base Adresse Nationale. Route AUTONOME plutôt qu'une
// méthode Livewire : le même point d'entrée sert les trois écrans qui portent une adresse
// (fiche d'un bien, premier lancement, reprise de dossier), et un seul endroit garde les
// garde-fous — longueur minimale, débit, interrupteur.
//
// ⚠️ Elle ne rend JAMAIS d'erreur HTTP : une panne du service se lit dans la charge utile
// (`unavailable`), pas dans le code de statut. Un formulaire ne doit pas devenir invalidable
// parce qu'un service tiers est tombé.
Route::get('/adresse/recherche', \App\Http\Controllers\OpenData\AddressSearchController::class)
    ->middleware(['auth', \App\Http\Middleware\EnsureDossierContext::class])
    ->name('address.search');

Route::get('/demo', DemoLoginController::class)
    ->middleware('throttle:30,1')
    ->name('demo.start');

// Reprise d'un bac à sable depuis le lien envoyé par courriel. `signed` vérifie que le lien
// vient de nous et n'a pas expiré ; le contrôleur revérifie que la cible est bien un sandbox
// encore vivant — une signature n'est pas une autorisation.
Route::get('/demo/reprendre/{user}', DemoResumeController::class)
    ->middleware(['signed', 'throttle:30,1'])
    ->name('demo.resume');

Route::view('/confidentialite', 'legal.confidentialite')
    ->name('legal.confidentialite');
