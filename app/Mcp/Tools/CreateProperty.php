<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Crée un nouveau bien immobilier LMNP pour l\'utilisateur connecté. Les montants (acquisition_price, notary_fees, market_value) sont en euros. Les surfaces (total_area, rented_area) sont en m². Type : apartment, house, room, studio, other. Rental type : seasonal, long_term, mixed. TVA : exempt, liable.')]
#[IsDestructive]
class CreateProperty extends Tool
{
    protected string $name = 'create_property';

    public function handle(Request $request): Response
    {
        $name             = $request->get('name');
        $address          = $request->get('address', '');
        $city             = $request->get('city', '');
        $postalCode       = $request->get('postal_code', '');
        $type             = $request->get('type', Property::TYPE_APARTMENT);
        $totalArea        = (int) $request->get('total_area', 0);
        $rentedArea       = (int) $request->get('rented_area', 0);
        $acquisitionDate  = $request->get('acquisition_date');
        $acquisitionPrice = $request->get('acquisition_price', 0);
        $notaryFees       = $request->get('notary_fees', 0);
        $marketValue      = $request->get('market_value');
        $marketValueDate  = $request->get('market_value_date');
        $landPercentage   = (int) $request->get('land_percentage', 15);
        $rentalStartDate  = $request->get('rental_start_date', $acquisitionDate);
        $rentalType       = $request->get('rental_type', Property::RENTAL_SEASONAL);
        $tvaRegime        = $request->get('tva_regime', Property::TVA_EXEMPT);
        $isPrimaryResidence = (bool) $request->get('is_primary_residence', false);
        $notes            = $request->get('notes');

        if (! $name) {
            return Response::error('Le champ name est requis.');
        }

        $validTypes = [Property::TYPE_APARTMENT, Property::TYPE_HOUSE, Property::TYPE_ROOM, Property::TYPE_STUDIO, Property::TYPE_OTHER];
        if (! in_array($type, $validTypes)) {
            return Response::error('Type invalide. Valeurs : ' . implode(', ', $validTypes));
        }

        $validRentalTypes = [Property::RENTAL_SEASONAL, Property::RENTAL_LONG_TERM, Property::RENTAL_MIXED];
        if (! in_array($rentalType, $validRentalTypes)) {
            return Response::error('rental_type invalide. Valeurs : ' . implode(', ', $validRentalTypes));
        }

        $property = Property::create([
            'user_id'              => Auth::id(),
            'name'                 => $name,
            'address'              => $address,
            'city'                 => $city,
            'postal_code'          => $postalCode,
            'type'                 => $type,
            'total_area'           => $totalArea,
            'rented_area'          => $rentedArea,
            'acquisition_date'     => $acquisitionDate,
            'acquisition_price'    => (int) bcmul((string) $acquisitionPrice, '100', 0),
            'notary_fees'          => (int) bcmul((string) $notaryFees, '100', 0),
            'market_value'         => $marketValue !== null ? (int) bcmul((string) $marketValue, '100', 0) : null,
            'market_value_date'    => $marketValueDate,
            'land_percentage'      => $landPercentage,
            'rental_start_date'    => $rentalStartDate,
            'rental_type'          => $rentalType,
            'tva_regime'           => $tvaRegime,
            'is_primary_residence' => $isPrimaryResidence,
            'notes'                => $notes,
        ]);

        return Response::json([
            'success'  => true,
            'property' => [
                'id'               => $property->id,
                'name'             => $property->name,
                'city'             => $property->city,
                'type'             => $property->type,
                'total_area'       => $property->total_area,
                'rented_area'      => $property->rented_area,
                'acquisition_date' => $property->acquisition_date?->format('Y-m-d'),
                'acquisition_price_euros' => round($property->acquisition_price / 100, 2),
                'rental_type'      => $property->rental_type,
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            $schema->string('name')->description('Nom du bien (ex : La Bastide)')->required(),
            $schema->string('address')->description('Adresse'),
            $schema->string('city')->description('Ville'),
            $schema->string('postal_code')->description('Code postal'),
            $schema->string('type')->description('Type : apartment, house, room, studio, other')->default('apartment'),
            $schema->integer('total_area')->description('Surface totale en m²'),
            $schema->integer('rented_area')->description('Surface louée en m²'),
            $schema->string('acquisition_date')->description('Date d\'acquisition (YYYY-MM-DD)')->required(),
            $schema->number('acquisition_price')->description('Prix d\'acquisition en euros')->required(),
            $schema->number('notary_fees')->description('Frais de notaire en euros')->default(0),
            $schema->number('market_value')->description('Valeur de marché actuelle en euros'),
            $schema->string('market_value_date')->description('Date d\'estimation de la valeur de marché (YYYY-MM-DD)'),
            $schema->integer('land_percentage')->description('Quote-part terrain non amortissable en % (défaut : 15)')->default(15),
            $schema->string('rental_start_date')->description('Date de début de location (YYYY-MM-DD)'),
            $schema->string('rental_type')->description('Type de location : seasonal, long_term, mixed')->default('seasonal'),
            $schema->string('tva_regime')->description('Régime TVA : exempt, liable')->default('exempt'),
            $schema->boolean('is_primary_residence')->description('Fait partie de la résidence principale')->default(false),
            $schema->string('notes')->description('Notes libres'),
        ];
    }
}
