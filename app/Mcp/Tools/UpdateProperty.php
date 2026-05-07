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

        foreach (['acquisition_price', 'notary_fees', 'market_value'] as $field) {
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
            $schema->integer('property_id')->description('ID du bien à modifier')->required(),
            $schema->string('name')->description('Nouveau nom'),
            $schema->string('address')->description('Nouvelle adresse'),
            $schema->string('city')->description('Nouvelle ville'),
            $schema->string('postal_code')->description('Nouveau code postal'),
            $schema->string('type')->description('Type : apartment, house, room, studio, other'),
            $schema->integer('total_area')->description('Surface totale en m²'),
            $schema->integer('rented_area')->description('Surface louée en m²'),
            $schema->number('acquisition_price')->description('Prix d\'acquisition en euros'),
            $schema->number('notary_fees')->description('Frais de notaire en euros'),
            $schema->number('market_value')->description('Valeur de marché en euros'),
            $schema->string('market_value_date')->description('Date d\'estimation valeur de marché (YYYY-MM-DD)'),
            $schema->integer('land_percentage')->description('Quote-part terrain en %'),
            $schema->string('acquisition_date')->description('Date d\'acquisition (YYYY-MM-DD)'),
            $schema->string('rental_start_date')->description('Date début location (YYYY-MM-DD)'),
            $schema->string('rental_type')->description('seasonal, long_term ou mixed'),
            $schema->string('tva_regime')->description('exempt ou liable'),
            $schema->boolean('is_primary_residence')->description('Résidence principale'),
            $schema->string('notes')->description('Notes libres'),
        ];
    }
}
