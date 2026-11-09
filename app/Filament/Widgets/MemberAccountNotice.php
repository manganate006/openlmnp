<?php

namespace App\Filament\Widgets;

use App\Support\DossierContext;
use Filament\Widgets\Widget;

/**
 * Explique en une phrase, sur le tableau de bord PRINCIPAL d'un compte « membre »
 * (App\Enums\AccountKind::Member), pourquoi il n'y a rien à saisir ici (lot
 * « dossiers membre », décision de Jérémie du 2026-10-15).
 *
 * ⚠️ Ne s'affiche en pratique que si Dashboard::mount() n'a PAS basculé ni redirigé
 * (zéro dossier ouvrable — voir LinkedDossier::hasActiveNonMemberBacker()) : un
 * compte membre avec un ou plusieurs dossiers ouvrables ne voit jamais ce tableau de
 * bord, il en est écarté avant que ce widget ne se rende.
 */
class MemberAccountNotice extends Widget
{
    protected static ?int $sort = -2;

    protected string $view = 'filament.widgets.member-account-notice';

    protected int|string|array $columnSpan = [
        'default' => 1,
        'sm' => 2,
        'lg' => 4,
    ];

    public static function canView(): bool
    {
        return DossierContext::isMemberActingAsPrincipal();
    }
}
