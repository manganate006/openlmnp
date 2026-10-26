<?php

namespace App\Services\Ai\Analysis\Prompts;

use App\Models\Expense;
use App\Models\Property;

/**
 * Prompt système et schéma JSON de l'extraction de justificatifs.
 *
 * Le prompt vit dans `documents.md` à côté de cette classe : versionné avec le
 * code, relisible sans lire de PHP, et modifiable sans toucher à la mécanique.
 *
 * Le schéma est VOLONTAIREMENT unique pour tous les types de document : le CDC
 * demande une seule passe (classement + extraction) afin de limiter coût et
 * latence, et `strict: true` interdit les unions discriminées. Les champs sont
 * donc une LISTE de {name, value, confidence, evidence} plutôt qu'un objet à
 * clés fixes : le schéma reste minuscule quel que soit le type, et le
 * Normalizer ignore tout nom qu'il ne connaît pas.
 */
final class Prompt
{
    /** Types de documents reconnus, dans l'ordre du CDC § 6.2. */
    public const TYPES = [
        'expense_invoice',
        'furniture_invoice',
        'works_invoice',
        'property_tax_notice',
        'insurance',
        'loan_schedule',
        'bank_statement',
        'income_statement',
        'liasse_2033',
        'unknown',
    ];

    /** Consigne système, mise en cache pour la durée de la requête. */
    public static function system(): string
    {
        static $cached = null;

        return $cached ??= file_get_contents(__DIR__ . '/documents.md');
    }

    /**
     * Contexte transmis au modèle : le strict nécessaire.
     *
     * Ce qui part : le nom du bien, son adresse, et s'il s'agit de la résidence
     * principale. RIEN d'autre — ni identité, ni email, ni SIREN, ni les autres
     * documents (CDC § 4.9).
     */
    public static function userContext(Property $property, ?string $forcedType = null): string
    {
        $lines = [
            'Bien concerné : ' . $property->name,
            'Adresse du bien : ' . trim($property->address . ' ' . $property->postal_code . ' ' . $property->city),
            'Ce bien est la résidence principale du propriétaire : ' . ($property->is_primary_residence ? 'oui' : 'non'),
        ];

        if ($forcedType !== null && in_array($forcedType, self::TYPES, strict: true)) {
            $lines[] = "L'utilisateur a indiqué que ce document est de type « {$forcedType} » : "
                . 'classe-le ainsi, sauf si le document dit manifestement le contraire.';
        }

        $lines[] = 'Analyse le document joint et renseigne le formulaire.';

        return implode("\n", $lines);
    }

    /**
     * Consigne de réparation, envoyée une seule fois quand la première réponse
     * n'est pas exploitable. Les annotations du parsing déjà facturé sont
     * rejouées par AiClient : le document n'est pas relu.
     */
    public static function repairInstruction(): string
    {
        return 'Ta réponse précédente n\'était pas un JSON conforme au schéma demandé. '
            . 'Renvoie UNIQUEMENT le document JSON valide, sans texte autour, sans bloc de code.';
    }

    /** Corps de `response_format.json_schema` (le socle ajoute `strict: true`). */
    public static function schema(): array
    {
        return [
            'name' => 'justificatif_lmnp',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['document_type', 'confidence', 'fields', 'items', 'schedule', 'lines', 'earnings', 'depreciation', 'carryforwards'],
                'properties' => [
                    'document_type' => [
                        'type' => 'string',
                        'enum' => self::TYPES,
                        'description' => 'Nature du document.',
                    ],
                    'confidence' => [
                        'type' => 'number',
                        'description' => 'Confiance globale dans l\'extraction, entre 0 et 1.',
                    ],
                    'fields' => [
                        'type' => 'array',
                        'description' => 'Champs extraits. Seuls les noms listés dans la consigne sont exploités.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['name', 'value', 'confidence', 'evidence'],
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'value' => ['type' => ['string', 'null']],
                                'confidence' => ['type' => 'number'],
                                'evidence' => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
                    'items' => [
                        'type' => 'array',
                        'description' => 'Articles d\'une facture de mobilier. Vide pour tout autre type.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['label', 'total_ttc', 'vat_rate', 'is_second_hand', 'confidence'],
                            'properties' => [
                                'label' => ['type' => 'string'],
                                'total_ttc' => ['type' => ['string', 'null']],
                                'vat_rate' => ['type' => ['string', 'null']],
                                'is_second_hand' => ['type' => ['boolean', 'null']],
                                'confidence' => ['type' => 'number'],
                            ],
                        ],
                    ],
                    'schedule' => [
                        'type' => 'array',
                        'description' => 'Échéances d\'un tableau d\'amortissement. Vide pour tout autre type.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['n', 'date', 'capital', 'interest', 'insurance', 'remaining'],
                            'properties' => [
                                'n' => ['type' => ['integer', 'null']],
                                'date' => ['type' => ['string', 'null']],
                                'capital' => ['type' => ['string', 'null']],
                                'interest' => ['type' => ['string', 'null']],
                                'insurance' => ['type' => ['string', 'null']],
                                'remaining' => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
                    'lines' => [
                        'type' => 'array',
                        'description' => 'Opérations d\'un relevé bancaire. Vide pour tout autre type.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['date', 'label', 'amount', 'direction', 'kind', 'category', 'confidence'],
                            'properties' => [
                                'date' => ['type' => ['string', 'null']],
                                'label' => ['type' => ['string', 'null']],
                                'amount' => ['type' => ['string', 'null']],
                                'direction' => ['type' => ['string', 'null'], 'enum' => ['debit', 'credit', null]],
                                'kind' => ['type' => ['string', 'null'], 'enum' => ['expense', 'income', 'loan_payment', 'ignore', null]],
                                'category' => ['type' => ['string', 'null'], 'enum' => [...array_keys(Expense::categoryLabels()), null]],
                                'confidence' => ['type' => 'number'],
                            ],
                        ],
                    ],
                    'earnings' => [
                        'type' => 'array',
                        'description' => 'Revenus mensuels d\'un relevé annuel de plateforme. Vide pour tout autre type.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['month', 'gross', 'net', 'confidence'],
                            'properties' => [
                                // ⚠️ Le NUMÉRO du mois, jamais une date : le relevé imprime
                                // « janvier », pas un jour. Demander une date reviendrait à
                                // faire inventer douze jours. L'année vient du champ racine
                                // `fiscal_year`, une fois, pas répétée douze fois.
                                'month' => ['type' => ['integer', 'null']],
                                // Les DEUX colonnes imprimées. Pas de champ « frais » : c'est
                                // `gross - net`, et un champ que le modèle doit CALCULER est un
                                // champ qu'il calculera faux. Les deux sont nullables : rien ne
                                // garantit qu'une autre plateforme publie les deux colonnes.
                                'gross' => ['type' => ['string', 'null']],
                                'net' => ['type' => ['string', 'null']],
                                'confidence' => ['type' => 'number'],
                            ],
                        ],
                    ],
                    'depreciation' => [
                        'type' => 'array',
                        'description' => 'Lignes du tableau 2033-C d\'une liasse. Vide pour tout autre type.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['label', 'base', 'duration_years', 'annual', 'cumul', 'confidence'],
                            'properties' => [
                                'label' => ['type' => 'string'],
                                'base' => ['type' => ['string', 'null']],
                                'duration_years' => ['type' => ['integer', 'null']],
                                'annual' => ['type' => ['string', 'null']],
                                'cumul' => ['type' => ['string', 'null']],
                                'confidence' => ['type' => 'number'],
                            ],
                        ],
                    ],
                    'carryforwards' => [
                        'type' => 'array',
                        'description' => 'Déficits reportables du 2033-D d\'une liasse, par millésime. Vide pour tout autre type.',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['origin_year', 'amount', 'confidence'],
                            'properties' => [
                                'origin_year' => ['type' => ['integer', 'null']],
                                'amount' => ['type' => ['string', 'null']],
                                'confidence' => ['type' => 'number'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
