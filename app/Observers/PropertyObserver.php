<?php

namespace App\Observers;

use App\Models\Property;
use App\Services\DepreciationService;

/**
 * Tient les composants ventilés en pourcentage d'accord avec la valeur du bien.
 *
 * ⚠️ C'est un OBSERVER et pas un appel dans le formulaire, délibérément : la base
 * amortissable peut changer par au moins six chemins — le formulaire du bien, l'assistant
 * de premier lancement, la reprise de dossier, l'estimation de valeur vénale, et les outils
 * MCP `update_property` / `create_property`. Brancher le recalage sur chacun d'eux, c'est
 * garantir d'en oublier un et de reproduire le défaut ailleurs.
 *
 * Le recalage ne touche que les composants `base_source = percentage`. Une base saisie à la
 * main est un choix de l'utilisateur : elle est laissée telle quelle, et l'écart éventuel
 * ressort au contrôle d'immobilisations de la liasse.
 */
class PropertyObserver
{
    /**
     * Les champs dont dépend `Property::depreciable_base`.
     *
     * Dérivé du calcul de l'accesseur, pas d'une liste d'intuition : valeur de référence
     * (`market_value` sinon `acquisition_price`), fraction bâtie (`land_percentage`) et
     * `quota_share`. Toute évolution de l'accesseur doit se répercuter ici — c'est ce que
     * vérifie `PropertyComponentResyncTest`.
     */
    private const CHAMPS_DE_LA_BASE = [
        'market_value',
        'acquisition_price',
        'land_percentage',
        'quota_share',
    ];

    public function updated(Property $property): void
    {
        if (! $property->wasChanged(self::CHAMPS_DE_LA_BASE)) {
            return;
        }

        app(DepreciationService::class)->resyncPercentageComponents($property);
    }
}
