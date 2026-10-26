<?php

namespace App\Filament\Widgets\Concerns;

use App\Support\DossierContext;

/**
 * Widget du tableau de bord qui parle des biens, recettes ou charges du compte : masqué pour
 * un compte « membre » resté dans son propre principal (App\Support\DossierContext::
 * isMemberActingAsPrincipal()), qui n'a ni biens ni menu « Mes biens ». Il ne voit alors que
 * App\Filament\Widgets\MemberAccountNotice.
 *
 * D6 du test bêta du 2026-10-15 : seul le guide de démarrage (OnboardingChecklist) était
 * masqué ; « Étape 1 : Ajoutez votre bien immobilier » (FiscalOverview) et le suivi par année
 * (ExpenseCompletionGrid) restaient affichés sous la phrase « ce compte ne permet pas de
 * saisir des biens personnels ». Une fois basculé DANS un dossier, tout revient.
 */
trait HiddenForMemberPrincipal
{
    public static function canView(): bool
    {
        return ! DossierContext::isMemberActingAsPrincipal();
    }
}
