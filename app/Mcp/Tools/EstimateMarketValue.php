<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Services\OpenData\DvfUnavailable;
use App\Services\OpenData\MarketValueEstimator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Estimation de la valeur vénale d'après les ventes DVF de la commune.
 *
 * ⚠️ **Le seul outil MCP qui interroge un service externe** — il transmet la commune, le type
 * de bien et la surface à data.gouv.fr, jamais un montant ni une adresse. Il est donc :
 * absent de l'allowlist du token démo public (`config('mcp.demo.tools')`) — un token partagé
 * ne doit pas pouvoir faire télécharger notre serveur — et coupé par `DVF_ENABLED=false`.
 *
 * Pourquoi cet outil : la valeur vénale est le champ le plus souvent rempli au juger de tout
 * le formulaire, et un assistant qui crée un bien avec `create_property` est exactement au
 * moment où il faut la proposer.
 */
#[Description("Estime la valeur vénale d'un logement à partir des ventes réelles de sa commune (données DVF de la DGFiP, publiées sur data.gouv.fr sous Licence Ouverte 2.0). Utile pour la base amortissable quand le bien était détenu avant sa mise en location : c'est alors la valeur vénale à cette date, et non le prix d'achat, qui la détermine. Fournir soit property_id (les caractéristiques sont reprises du bien), soit commune + property_type + area_m2. Ce n'est PAS une expertise : une médiane communale ignore l'étage, l'état et le DPE.")]
#[IsReadOnly]
class EstimateMarketValue extends Tool
{
    protected string $name = 'estimate_market_value';

    public function __construct(private MarketValueEstimator $estimator) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer()
                ->description('Bien existant dont on reprend la commune, le type et la surface. Alternative à commune/property_type/area_m2.'),
            'commune' => $schema->string()
                ->description('Nom de commune, code postal ou code INSEE. Ignoré si property_id est fourni.'),
            'property_type' => $schema->string()
                ->description('apartment, house, room, studio ou other. Défaut : apartment.'),
            'area_m2' => $schema->integer()
                ->description('Surface habitable en m². Ignorée si property_id est fourni.'),
            'year' => $schema->integer()
                ->description("Millésime de référence — prendre l'année de mise en location. Défaut : le plus récent publié."),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'nullable|integer',
            'commune' => 'nullable|string|max:100',
            'property_type' => 'nullable|string|in:apartment,house,room,studio,other',
            'area_m2' => 'nullable|integer|min:1|max:10000',
            'year' => 'nullable|integer|min:2000|max:2099',
        ]);

        [$query, $type, $area] = $this->target($validated);

        if ($query === '' || $area <= 0) {
            return Response::error('Fournir soit property_id, soit commune et area_m2.');
        }

        try {
            $communes = $this->estimator->communes($query);
        } catch (DvfUnavailable $e) {
            return Response::error($e->getMessage());
        }

        if ($communes === []) {
            return Response::error("Aucune commune trouvée pour « {$query} ».");
        }

        // Plusieurs communes possibles (code postal à cheval, homonymes) : on rend la main
        // plutôt que d'en choisir une au hasard — l'écart entre deux communes voisines se
        // compte en dizaines de milliers d'euros de base amortissable.
        if (count($communes) > 1) {
            return Response::json([
                'ambiguous' => true,
                'message' => 'Plusieurs communes correspondent. Rappeler l\'outil avec le code INSEE voulu.',
                'candidates' => array_map(fn (array $c) => [
                    'insee_code' => $c['code'],
                    'name' => $c['nom'],
                    'department' => $c['departement'],
                    'postal_code' => $c['code_postal'],
                ], $communes),
            ]);
        }

        $commune = $communes[0];

        try {
            $estimate = $this->estimator->estimate($commune['code'], $type, $area, $validated['year'] ?? null);
        } catch (DvfUnavailable $e) {
            return Response::error($e->getMessage());
        }

        if (! $estimate['enough']) {
            return Response::json([
                'estimated' => false,
                'reason' => sprintf(
                    'Pas assez de ventes comparables : %d sur les millésimes %s, minimum %d. Aucune valeur n\'est proposée — une médiane sur si peu de transactions serait trompeuse.',
                    $estimate['sample_size'],
                    implode(', ', $estimate['years']),
                    $estimate['minimum'],
                ),
                'commune' => $commune['nom'],
                'insee_code' => $commune['code'],
                'sample_size' => $estimate['sample_size'],
            ]);
        }

        return Response::json([
            'estimated' => true,
            'market_value_eur' => bcdiv((string) $estimate['value_cents'], '100', 2),
            'price_per_m2_eur' => bcdiv((string) $estimate['price_per_m2_cents'], '100', 2),
            'commune' => $commune['nom'],
            'insee_code' => $commune['code'],
            'department' => $commune['departement'],
            'property_type' => $estimate['type'],
            'area_m2' => $estimate['area_m2'],
            'sample_size' => $estimate['sample_size'],
            'years' => $estimate['years'],
            'disclaimer' => "Ce n'est pas une expertise : une médiane communale ignore l'étage, l'état, l'exposition et le DPE. C'est un élément de justification de la base amortissable, à conserver avec le détail de l'échantillon.",
            'source' => 'Demandes de valeurs foncières (DGFiP), data.gouv.fr, Licence Ouverte 2.0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: string, 1: string, 2: int}
     */
    private function target(array $validated): array
    {
        if (! empty($validated['property_id'])) {
            // `find` et non `findOrFail` : le scope global filtre déjà par utilisateur, et un
            // id d'autrui doit répondre « introuvable » comme un id inexistant.
            $property = Property::find($validated['property_id']);

            if ($property !== null) {
                return [
                    trim((string) ($property->postal_code ?: $property->city)),
                    (string) $property->type,
                    (int) $property->total_area,
                ];
            }
        }

        return [
            trim((string) ($validated['commune'] ?? '')),
            (string) ($validated['property_type'] ?? 'apartment'),
            (int) ($validated['area_m2'] ?? 0),
        ];
    }
}
