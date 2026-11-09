<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Met à jour les champs d\'un bien immobilier existant. Seuls les champs fournis sont modifiés. Les montants sont en euros, les surfaces en m². residence_situation : separate, part_of_residence, independent_unit_in_residence. tourism_classification : furnished_tourism_unclassified, furnished_tourism_classified, long_term.')]
#[IsDestructive]
#[IsIdempotent]
class UpdateProperty extends Tool
{
    protected string $name = 'update_property';

    public function handle(Request $request): Response
    {
        $propertyId = $request->get('property_id');
        if (! $propertyId) {
            return Response::error('Le champ property_id est requis.');
        }

        $property = Property::findOrFail((int) $propertyId);

        $updates = [];

        foreach (['name', 'address', 'city', 'postal_code', 'type', 'rental_type', 'tva_regime', 'residence_situation', 'tourism_classification', 'notes', 'dpe_number', 'dpe_class'] as $field) {
            $val = $request->get($field);
            if ($val !== null) {
                $updates[$field] = $val;
            }
        }

        foreach (['total_area', 'rented_area', 'land_percentage'] as $field) {
            $val = $request->get($field);
            if ($val !== null) {
                $updates[$field] = (int) $val;
            }
        }

        // ⚠️ `total_area` à zéro (ou négative) annule silencieusement la quote-part de
        // surface louée pour TOUT le bien — voir la garde de `create_property`. Un update
        // partiel (seule `rented_area` fournie) est vérifié contre la surface ACTUELLE du
        // bien, pas contre la valeur soumise, absente dans ce cas.
        if (array_key_exists('total_area', $updates) && $updates['total_area'] <= 0) {
            return Response::error('total_area doit être supérieure à 0.');
        }

        if (array_key_exists('rented_area', $updates)) {
            if ($updates['rented_area'] <= 0) {
                return Response::error('rented_area doit être supérieure à 0.');
            }

            $effectiveTotalArea = $updates['total_area'] ?? $property->total_area;
            if ($updates['rented_area'] > $effectiveTotalArea) {
                return Response::error('rented_area ne peut pas dépasser total_area.');
            }
        } elseif (array_key_exists('total_area', $updates) && $updates['total_area'] < $property->rented_area) {
            return Response::error('total_area ne peut pas devenir inférieure à rented_area (' . $property->rented_area . ' m²).');
        }

        foreach (['acquisition_price', 'notary_fees', 'agency_fees', 'market_value'] as $field) {
            $val = $request->get($field);
            if ($val !== null) {
                $updates[$field] = (int) bcmul((string) $val, '100', 0);
            }
        }

        foreach (['acquisition_date', 'rental_start_date', 'market_value_date', 'dpe_established_at', 'dpe_valid_until'] as $field) {
            $val = $request->get($field);
            if ($val !== null) {
                $updates[$field] = $val;
            }
        }

        // Première mise en location (micro-BIC compris). Une chaîne vide l'efface : le bien
        // retombe alors sur le début du réel.
        if ($request->has('activity_start_date')) {
            $updates['activity_start_date'] = $request->get('activity_start_date') ?: null;
        }

        $effectiveActivity = array_key_exists('activity_start_date', $updates)
            ? $updates['activity_start_date']
            : $property->activity_start_date?->format('Y-m-d');
        $effectiveReal = $updates['rental_start_date'] ?? $property->rental_start_date?->format('Y-m-d');

        if ($effectiveActivity !== null && $effectiveReal !== null && $effectiveActivity > $effectiveReal) {
            return Response::error('activity_start_date (première mise en location) ne peut pas être postérieure à rental_start_date (début du réel).');
        }

        // ⚠️ Déprécié : utiliser residence_situation, qui prend le dessus si fourni.
        $isPrimary = $request->get('is_primary_residence');
        if ($isPrimary !== null) {
            $updates['is_primary_residence'] = (bool) $isPrimary;
        }

        if (isset($updates['residence_situation'])) {
            $validSituations = [Property::RESIDENCE_SEPARATE, Property::RESIDENCE_PART_OF_RESIDENCE, Property::RESIDENCE_INDEPENDENT_UNIT];
            if (! in_array($updates['residence_situation'], $validSituations, true)) {
                return Response::error('residence_situation invalide. Valeurs : ' . implode(', ', $validSituations));
            }
        }

        if (isset($updates['tourism_classification'])) {
            $validClassifications = [Property::CLASSIFICATION_UNCLASSIFIED, Property::CLASSIFICATION_CLASSIFIED, Property::CLASSIFICATION_LONG_TERM];
            if (! in_array($updates['tourism_classification'], $validClassifications, true)) {
                return Response::error('tourism_classification invalide. Valeurs : ' . implode(', ', $validClassifications));
            }
        }

        if (empty($updates)) {
            return Response::error('Aucun champ à modifier fourni.');
        }

        $property->update($updates);

        return Response::json([
            'success'  => true,
            'property_id' => $property->id,
            'updated_fields' => array_keys($updates),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id'          => $schema->integer()->description('ID du bien à modifier')->required(),
            'name'                 => $schema->string()->description('Nouveau nom'),
            'address'              => $schema->string()->description('Nouvelle adresse'),
            'city'                 => $schema->string()->description('Nouvelle ville'),
            'postal_code'          => $schema->string()->description('Nouveau code postal'),
            'type'                 => $schema->string()->description('Type : apartment, house, room, studio, other'),
            'total_area'           => $schema->integer()->description('Surface totale en m²'),
            'rented_area'          => $schema->integer()->description('Surface louée en m²'),
            'acquisition_price'    => $schema->number()->description('Prix d\'acquisition en euros'),
            'notary_fees'          => $schema->number()->description('Frais de notaire en euros'),
            'agency_fees'          => $schema->number()->description('Honoraires agence en euros'),
            'market_value'         => $schema->number()->description('Valeur de marché en euros'),
            'market_value_date'    => $schema->string()->description('Date d\'estimation valeur de marché (YYYY-MM-DD)'),
            'dpe_number'           => $schema->string()->description('Numéro du DPE'),
            'dpe_class'            => $schema->string()->description('Classe du DPE : A à G'),
            'dpe_established_at'   => $schema->string()->description('Date d\'établissement du DPE (YYYY-MM-DD)'),
            'dpe_valid_until'      => $schema->string()->description('Date de fin de validité du DPE (YYYY-MM-DD)'),
            'land_percentage'      => $schema->integer()->description('Quote-part terrain en %'),
            'acquisition_date'     => $schema->string()->description('Date d\'acquisition (YYYY-MM-DD)'),
            'rental_start_date'    => $schema->string()->description('Début du régime réel (YYYY-MM-DD)'),
            'activity_start_date'  => $schema->string()->description('Première mise en location, micro-BIC compris (YYYY-MM-DD) ; chaîne vide pour l\'effacer (= rental_start_date)'),
            'rental_type'          => $schema->string()->description('seasonal, long_term ou mixed'),
            'tourism_classification' => $schema->string()->description('Classement micro-BIC : furnished_tourism_unclassified, furnished_tourism_classified, long_term'),
            'tva_regime'           => $schema->string()->description('exempt ou liable'),
            'residence_situation'  => $schema->string()->description('separate, part_of_residence ou independent_unit_in_residence'),
            'is_primary_residence' => $schema->boolean()->description('⚠️ Déprécié, utiliser residence_situation. Résidence principale'),
            'notes'                => $schema->string()->description('Notes libres'),
        ];
    }
}
