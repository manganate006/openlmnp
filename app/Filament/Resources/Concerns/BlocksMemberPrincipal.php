<?php

namespace App\Filament\Resources\Concerns;

use App\Support\DossierContext;

/**
 * Un compte « membre » (App\Enums\AccountKind::Member) n'a jamais de dossier à lui :
 * son espace PRINCIPAL ne sert pas à saisir des biens (lot « dossiers membre »,
 * décision de Jérémie du 2026-10-15). Portée par les Resources « Mes biens » et
 * ressources liées (Properties, PropertyWorks, Incomes, Expenses, Furniture, Loans,
 * FiscalYears) : masque la navigation ET bloque la création en direct.
 *
 * ⚠️ Ne s'applique QUE dans le PRINCIPAL d'un compte membre, jamais une fois basculé
 * DANS un dossier lié (`is_linked_dossier` y vaut alors `true` sur `Auth::user()`,
 * donc cette garde se tait) : c'est là, et seulement là, qu'un compte membre saisit
 * des biens — ceux du dossier, jamais les siens.
 *
 * ⚠️ `canViewAny()` masque la navigation (HasNavigation::registerNavigationItems()
 * appelle `canAccess()` → `canViewAny()`) mais n'empêche PAS, à elle seule, une
 * visite directe de l'URL de liste — sans gravité ici : `BelongsToUserScope` filtre
 * déjà par utilisateur, et un compte membre n'a JAMAIS de ligne à lui dans ces
 * tables, la liste serait de toute façon vide. C'est `canCreate()` qui porte le
 * vrai blocage : `Filament\Resources\Pages\CreateRecord::authorizeAccess()` fait un
 * `abort_unless(..., 403)` dessus, y compris sur une visite directe.
 */
trait BlocksMemberPrincipal
{
    protected static function blockedForMemberPrincipal(): bool
    {
        return DossierContext::isMemberActingAsPrincipal();
    }

    public static function canViewAny(): bool
    {
        return parent::canViewAny() && ! static::blockedForMemberPrincipal();
    }

    public static function canCreate(): bool
    {
        return parent::canCreate() && ! static::blockedForMemberPrincipal();
    }
}
