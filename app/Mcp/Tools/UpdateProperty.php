<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Met à jour les champs d\'un bien immobilier existant. Seuls les champs fournis sont modifiés. Les montants sont en euros, les surfaces en m².')]
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

        foreach (['name', 'address', 'city', 'postal_code', 'type', 'rental_type', 'tva_regime', 'notes'] as $field) {
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

        foreach (['acquisition_price', 'notary_fees', 'agency_fees', 'market_value'] as $field) {
            $val = $request->get($field);
            if ($val !== null) {
                $updates[$field] = (int) bcmul((string) $val, '100', 0);
            }
        }

        foreach (['acquisition_date', 'rental_start_date', 'market_value_date'] as $field) {
            $val = $request->get($field);
            if ($val !== null) {
                $updates[$field] = $val;
            }
        }

        $isPrimary = $request->get('is_primary_residence');
        if ($isPrimary !== null) {
            $updates['is_primary_residence'] = (bool) $isPrimary;
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
            'property_id'          => $schema->integer('ID du bien à modifier')->required(),
            'name'                 => $schema->string('Nouveau nom'),
            'address'              => $schema->string('Nouvelle adresse'),
            'city'                 => $schema->string('Nouvelle ville'),
            'postal_code'          => $schema->string('Nouveau code postal'),
            'type'                 => $schema->string('Type : apartment, house, room, studio, other'),
            'total_area'           => $schema->integer('Surface totale en m²'),
            'rented_area'          => $schema->integer('Surface louée en m²'),
            'acquisition_price'    => $schema->number('Prix d\'acquisition en euros'),
            'notary_fees'          => $schema->number('Frais de notaire en euros'),
            'agency_fees'          => $schema->number('Honoraires agence en euros'),
            'market_value'         => $schema->number('Valeur de marché en euros'),
            'market_value_date'    => $schema->string('Date d\'estimation valeur de marché (YYYY-MM-DD)'),
            'land_percentage'      => $schema->integer('Quote-part terrain en %'),
            'acquisition_date'     => $schema->string('Date d\'acquisition (YYYY-MM-DD)'),
            'rental_start_date'    => $schema->string('Date début location (YYYY-MM-DD)'),
            'rental_type'          => $schema->string('seasonal, long_term ou mixed'),
            'tva_regime'           => $schema->string('exempt ou liable'),
            'is_primary_residence' => $schema->boolean('Résidence principale'),
            'notes'                => $schema->string('Notes libres'),
        ];
    }
}
