<?php

namespace App\Enums;

/**
 * Nature d'un compte PRINCIPAL (jamais un dossier lié — voir `users.is_linked_dossier`,
 * qui reste le marqueur du User d'un dossier, sans rapport avec cette colonne-ci).
 *
 * `Member` : accès gratuit d'indivisaire, créé UNIQUEMENT depuis une invitation de
 * dossier valide (décision de Jérémie du 2026-10-15, voir
 * `_admin/docs/plan-dossiers-lies-2026-10-14.md` et le compte rendu du lot « dossiers
 * membre »). Un tel compte :
 *   - n'a JAMAIS de dossier À LUI (quota toujours 0, `App\Support\DossierQuota::for()`
 *     prime sur toute autre règle, y compris une valeur posée par erreur en base) ;
 *   - ne saisit rien dans son espace PRINCIPAL (`App\Filament\Resources\Concerns\
 *     BlocksMemberPrincipal`) : son seul usage est d'ouvrir le ou les dossiers où il a
 *     été invité ;
 *   - ne devient jamais responsable d'un dossier (`LinkedDossier::actingOwner()`
 *     l'exclut explicitement — la succession ne passe qu'à un compte `Standard` actif) ;
 *   - n'ouvre un dossier que si celui-ci compte au moins un membre ACTIF qui n'est PAS
 *     lui-même un compte membre (anti-détournement, voir `LinkedDossier::
 *     hasActiveNonMemberBacker()` et `DossierContext::switchTo()`/`validate()`).
 *
 * Promu en `Standard` SANS perdre son appartenance au dossier si la personne s'abonne
 * un jour (`App\Http\Controllers\Api\ProvisioningController::promoteMemberAccount()`) —
 * l'appartenance à `linked_dossier_members` ne pend que de `user_id`, jamais réécrite
 * par cette promotion.
 *
 * ⚠️ Colonne choisie plutôt qu'un booléen `is_member` : un compte « membre » n'est pas
 * la négation d'un attribut binaire, c'est une CATÉGORIE de compte parmi d'autres déjà
 * esquissées par le plan (dossiers payants futurs, décision n°10) — un enum extensible
 * évite une nouvelle migration/colonne le jour où une troisième nature de compte
 * apparaît, et se lit sans double négation (`$user->account_kind === self::Member`
 * plutôt que `! $user->is_standard`). Même convention que `NavMode`/`DossierMemberRole` :
 * cast enum sur une colonne `string`, jamais un entier nu.
 */
enum AccountKind: string
{
    case Standard = 'standard';
    case Member = 'member';
}
