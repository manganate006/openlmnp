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

#[Description('Crée un nouveau bien immobilier LMNP pour l\'utilisateur connecté. Les montants (acquisition_price, notary_fees, market_value) sont en euros. Les surfaces (total_area, rented_area) sont en m². Type : apartment, house, room, studio, other. Rental type : seasonal, long_term, mixed. TVA : exempt, liable. residence_situation : separate (logement distinct), part_of_residence (pièce(s) de la résidence principale), independent_unit_in_residence (logement indépendant dans la résidence principale ou sur la même propriété — pas de limite de 120 jours, pas d\'art. 35 bis, CFE due en principe). tourism_classification : furnished_tourism_unclassified (30 %), furnished_tourism_classified (50 %), long_term (50 %) — abattement micro-BIC.')]
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
        $agencyFees       = $request->get('agency_fees', 0);
        $marketValue      = $request->get('market_value');
        $marketValueDate  = $request->get('market_value_date');
        $dpeNumber        = $request->get('dpe_number');
        $dpeClass         = $request->get('dpe_class');
        $dpeEstablishedAt = $request->get('dpe_established_at');
        $dpeValidUntil    = $request->get('dpe_valid_until');
        $landPercentage   = (int) $request->get('land_percentage', 15);
        $rentalStartDate  = $request->get('rental_start_date', $acquisitionDate);
        $activityStartDate = $request->get('activity_start_date') ?: null;
        $rentalType       = $request->get('rental_type', Property::RENTAL_SEASONAL);
        $tvaRegime        = $request->get('tva_regime', Property::TVA_EXEMPT);
        // ⚠️ `is_primary_residence` reste accepté pour rétrocompatibilité mais est
        // DÉPRÉCIÉ : `residence_situation` est désormais seule lue si fournie (sinon
        // dérivée de `is_primary_residence` par le modèle). Aucun calcul ne dépend plus
        // de l'ancien booléen.
        $isPrimaryResidence   = $request->has('is_primary_residence') ? (bool) $request->get('is_primary_residence') : null;
        $residenceSituation   = $request->get('residence_situation');
        $tourismClassification = $request->get('tourism_classification');
        $notes            = $request->get('notes');

        if (! $name) {
            return Response::error('Le champ name est requis.');
        }

        // On ne peut pas être au réel avant d'exercer : la première location précède
        // (ou égale) le début du réel. Comparaison de chaînes ISO, exacte.
        if ($activityStartDate !== null && $rentalStartDate !== null && $activityStartDate > $rentalStartDate) {
            return Response::error('activity_start_date (première mise en location) ne peut pas être postérieure à rental_start_date (début du réel).');
        }

        // ⚠️ Sans cette garde, `total_area`/`rented_area` omis retombaient sur 0 : la
        // quote-part de surface louée (`Property::quota_share`) multiplie la base
        // amortissable ET quasiment toutes les charges au prorata. Un bien créé sans
        // surface amortissait silencieusement à zéro, sans qu'aucun montant n'ait l'air
        // faux — un zéro qui ressemble exactement à un résultat fiscal exact.
        if ($totalArea <= 0) {
            return Response::error('Le champ total_area est requis et doit être supérieur à 0.');
        }

        if ($rentedArea <= 0) {
            return Response::error('Le champ rented_area est requis et doit être supérieur à 0.');
        }

        if ($rentedArea > $totalArea) {
            return Response::error('rented_area ne peut pas dépasser total_area.');
        }

        $validTypes = [Property::TYPE_APARTMENT, Property::TYPE_HOUSE, Property::TYPE_ROOM, Property::TYPE_STUDIO, Property::TYPE_OTHER];
        if (! in_array($type, $validTypes)) {
            return Response::error('Type invalide. Valeurs : ' . implode(', ', $validTypes));
        }

        $validRentalTypes = [Property::RENTAL_SEASONAL, Property::RENTAL_LONG_TERM, Property::RENTAL_MIXED];
        if (! in_array($rentalType, $validRentalTypes)) {
            return Response::error('rental_type invalide. Valeurs : ' . implode(', ', $validRentalTypes));
        }

        $validSituations = [Property::RESIDENCE_SEPARATE, Property::RESIDENCE_PART_OF_RESIDENCE, Property::RESIDENCE_INDEPENDENT_UNIT];
        if ($residenceSituation !== null && ! in_array($residenceSituation, $validSituations, true)) {
            return Response::error('residence_situation invalide. Valeurs : ' . implode(', ', $validSituations));
        }

        $validClassifications = [Property::CLASSIFICATION_UNCLASSIFIED, Property::CLASSIFICATION_CLASSIFIED, Property::CLASSIFICATION_LONG_TERM];
        if ($tourismClassification !== null && ! in_array($tourismClassification, $validClassifications, true)) {
            return Response::error('tourism_classification invalide. Valeurs : ' . implode(', ', $validClassifications));
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
            'agency_fees'          => (int) bcmul((string) $agencyFees, '100', 0),
            'market_value'         => $marketValue !== null ? (int) bcmul((string) $marketValue, '100', 0) : null,
            'market_value_date'    => $marketValueDate,
            'dpe_number'           => $dpeNumber,
            'dpe_class'            => $dpeClass,
            'dpe_established_at'   => $dpeEstablishedAt,
            'dpe_valid_until'      => $dpeValidUntil,
            'land_percentage'      => $landPercentage,
            'rental_start_date'    => $rentalStartDate,
            'activity_start_date'  => $activityStartDate,
            'rental_type'          => $rentalType,
            'tourism_classification' => $tourismClassification,
            'tva_regime'           => $tvaRegime,
            'is_primary_residence' => $isPrimaryResidence ?? false,
            'residence_situation'  => $residenceSituation,
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
                'residence_situation' => $property->residence_situation,
                'tourism_classification' => $property->tourism_classification,
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name'                => $schema->string()->description('Nom du bien (ex : La Bastide)')->required(),
            'address'             => $schema->string()->description('Adresse'),
            'city'                => $schema->string()->description('Ville'),
            'postal_code'         => $schema->string()->description('Code postal'),
            'type'                => $schema->string()->description('Type : apartment, house, room, studio, other'),
            'total_area'          => $schema->integer()->description('Surface totale en m²')->required(),
            'rented_area'         => $schema->integer()->description('Surface louée en m²')->required(),
            'acquisition_date'    => $schema->string()->description('Date d\'acquisition (YYYY-MM-DD)')->required(),
            'acquisition_price'   => $schema->number()->description('Prix d\'acquisition en euros')->required(),
            'notary_fees'         => $schema->number()->description('Frais de notaire en euros'),
            'agency_fees'         => $schema->number()->description('Honoraires agence en euros'),
            'market_value'        => $schema->number()->description('Valeur de marché actuelle en euros'),
            'market_value_date'   => $schema->string()->description('Date d\'estimation de la valeur de marché (YYYY-MM-DD)'),
            'dpe_number'          => $schema->string()->description('Numéro du DPE'),
            'dpe_class'           => $schema->string()->description('Classe du DPE : A à G'),
            'dpe_established_at'  => $schema->string()->description('Date d\'établissement du DPE (YYYY-MM-DD)'),
            'dpe_valid_until'     => $schema->string()->description('Date de fin de validité du DPE (YYYY-MM-DD)'),
            'land_percentage'     => $schema->integer()->description('Quote-part terrain non amortissable en % (défaut : 15)'),
            'rental_start_date'   => $schema->string()->description('Début du régime réel (YYYY-MM-DD) : ouverture du premier exercice au réel. Défaut : date d\'acquisition'),
            'activity_start_date' => $schema->string()->description('Première mise en location, micro-BIC compris (YYYY-MM-DD). À renseigner seulement si le bien a été loué au micro-BIC avant le réel : les annuités de ces années sont réputées pratiquées (dans le cumul, jamais déduites). Vide = rental_start_date'),
            'rental_type'         => $schema->string()->description('Type de location : seasonal, long_term, mixed'),
            'tourism_classification' => $schema->string()->description('Classement micro-BIC : furnished_tourism_unclassified (30 %), furnished_tourism_classified (50 %), long_term (50 %). Défaut dérivé de rental_type.'),
            'tva_regime'          => $schema->string()->description('Régime TVA : exempt, liable'),
            'residence_situation' => $schema->string()->description('Situation : separate, part_of_residence, independent_unit_in_residence'),
            'is_primary_residence' => $schema->boolean()->description('⚠️ Déprécié, utiliser residence_situation. Fait partie de la résidence principale'),
            'notes'               => $schema->string()->description('Notes libres'),
        ];
    }
}
