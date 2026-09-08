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
     * ⚠️ Ce sont des COLONNES, jamais des accesseurs. `wasChanged()` interroge les attributs
     * réellement écrits : il a porté `'quota_share'` du 2026-09-06 au 2026-09-08 alors que
     * c'est un accesseur (`rented_area / total_area`), donc il rendait **toujours faux** et
     * modifier une surface ne recalait rien. La quote-part est ici décomposée en ses deux
     * colonnes, et `acquisition_fees_treatment` + les deux montants de frais s'y ajoutent
     * depuis que le traitement « intégrés au coût du bien » les fait entrer dans la base.
     *
     * `PropertyComponentResyncTest` mesure désormais l'EFFET de chacun, un par un — la
     * version textuelle de ce garde-fou passait au vert sur un observer aveugle.
     */
    private const CHAMPS_DE_LA_BASE = [
        'market_value',
        'acquisition_price',
        'land_percentage',
        'rented_area',
        'total_area',
        'acquisition_fees_treatment',
        'notary_fees',
        'agency_fees',
    ];

    public function updated(Property $property): void
    {
        if (! $property->wasChanged(self::CHAMPS_DE_LA_BASE)) {
            return;
        }

        app(DepreciationService::class)->resyncPercentageComponents($property);
    }
}
