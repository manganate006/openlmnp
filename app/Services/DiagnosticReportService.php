<?php

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;

/**
 * Le rapport de diagnostic : tout ce qui produit une liasse, en un texte transmissible.
 *
 * ⚠️ RAISON D'ÊTRE, à ne pas perdre de vue en le faisant évoluer. Deux utilisateurs ont
 * signalé le même symptôme — « ma base amortissable ne correspond pas à ma liasse » — en
 * septembre 2026, et dans les deux cas il a fallu leur faire refaire le diagnostic à la main.
 * Le premier a joint deux captures d'écran, dont on a pu reconstituer son dossier à l'euro ;
 * le second n'en a pas joint, et son cas est resté indécidable. Ce rapport existe pour que le
 * second cas ne se reproduise pas.
 *
 * Trois principes qui ne se négocient pas :
 *
 *   1. **Aucune donnée nominative.** Ni nom, ni adresse, ni ville, ni code INSEE, ni e-mail.
 *      Les biens sont numérotés. Les intitulés saisis par l'utilisateur (composants, travaux,
 *      mobilier) sont conservés parce qu'ils servent au diagnostic — l'écran le dit, pour
 *      qu'il puisse les caviarder avant d'envoyer. ⚠️ Ne pas réutiliser `DossierArchive`,
 *      qui exporte l'adresse e-mail du propriétaire.
 *   2. **Aucun envoi.** Le rapport est affiché et téléchargeable, rien ne part sur le réseau.
 *      Une instance auto-hébergée chez un tiers n'expédie rien vers nos serveurs, au même
 *      titre que `feedback.forward_email`, vide par défaut.
 *   3. **Aucun calcul propre.** Tout vient de `DepreciationService` et de `TaxReturnService`.
 *      Un rapport qui recalculerait de son côté pourrait décrire une liasse différente de
 *      celle qu'on cherche à expliquer — et ce serait le pire des mensonges, puisqu'on le
 *      lirait en confiance.
 */
class DiagnosticReportService
{
    /**
     * Version du format. À incrémenter dès qu'un bloc change de forme, pour qu'un rapport
     * collé dans un ticket reste interprétable des mois plus tard.
     */
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private DepreciationService $depreciationService,
        private TaxReturnService $taxReturnService,
        private FiscalYearService $fiscalYearService,
        private UpdateService $updateService,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $user, int $year): array
    {
        $properties = Property::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->with(['components', 'works', 'furniture'])
            ->orderBy('id')
            ->get();

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at'   => now()->toIso8601String(),
            'year'           => $year,
            'environment'    => [
                'app_version'    => $this->updateService->getCurrentVersion(),
                'php_version'    => PHP_VERSION,
                'laravel_version' => app()->version(),
                'db_driver'      => config('database.default'),
                'install_type'   => config('services.telemetry.install_type', 'inconnu'),
            ],
            'properties'     => [],
        ];

        foreach ($properties as $index => $property) {
            $report['properties'][] = $this->describeProperty($property, $index + 1, $year);
        }

        if ($properties->isEmpty()) {
            return $report;
        }

        $fiscalYear = $this->fiscalYearService->getOrCreate($user, $year);

        $form2033A = $this->taxReturnService->compute2033A($fiscalYear, $properties, $year);
        $form2033B = $this->taxReturnService->compute2033B($fiscalYear, $properties, $year);
        $form2033C = $this->taxReturnService->compute2033C($properties, $year);

        $report['forms'] = [
            '2033A' => $form2033A,
            '2033C' => $form2033C,
        ];

        $report['checks'] = $this->taxReturnService->checks($form2033A, $form2033B, $form2033C, $properties);

        // Les trois écarts qui expliquent presque tous les tickets reçus sur la liasse.
        $allocated = $properties->sum(fn (Property $p) => (int) $p->components->sum('base_amount'));
        $base = $properties->sum(fn (Property $p) => (int) $p->depreciable_base);

        $report['gaps'] = [
            '044_moins_490'          => $form2033A['044'] - $form2033C['total_brut'],
            '572_moins_254'          => $form2033C['total_dotation'] - (int) $form2033B['254'],
            'base_moins_composants'  => $base - $allocated,
        ];

        return $report;
    }

    /** @return array<string, mixed> */
    private function describeProperty(Property $property, int $rank, int $year): array
    {
        return [
            // ⚠️ Un rang, jamais le nom : c'est souvent « Studio de Camille ».
            'label' => "Bien #{$rank}",
            'inputs' => [
                'prix_acquisition'        => (int) $property->acquisition_price,
                'valeur_venale'           => $property->market_value === null ? null : (int) $property->market_value,
                // La référence effectivement retenue, frais compris s'ils sont capitalisés :
                // c'est elle qui explique un total que l'utilisateur ne reconnaît pas.
                'valeur_reference'        => (int) $property->referenceValue(),
                'part_terrain_pct'        => (int) $property->land_percentage,
                'surface_totale'          => (int) $property->total_area,
                'surface_louee'           => (int) $property->rented_area,
                'quote_part'              => $property->quota_share,
                'residence_principale'    => (bool) $property->is_primary_residence,
                'date_mise_en_location'   => $property->rental_start_date?->format('Y-m-d'),
                'regime_tva'              => $property->tva_regime,
                'frais_notaire'           => (int) $property->notary_fees,
                'frais_agence'            => (int) $property->agency_fees,
                'traitement_frais'        => $property->acquisitionFeesTreatment(),
                'duree_frais_ans'         => $property->acquisitionFeesDurationYears(),
                // Le piège silencieux : des frais capitalisés qu'une valeur vénale écarte.
                'frais_ecartes_par_valeur_venale' => $property->acquisitionFeesIgnoredByMarketValue(),
            ],
            'base_amortissable' => (int) $property->depreciable_base,
            'components' => $property->components->map(fn (PropertyComponent $c) => [
                'nom'            => $c->name,
                'ligne_2033c'    => $c->cerfaCategory(),
                'base'           => (int) $c->base_amount,
                'part_pct'       => (string) $c->percentage,
                'duree_ans'      => (int) $c->duration_years,
                'source_base'    => $c->base_source,
                'date_depart'    => $c->depreciation_start_date?->format('Y-m-d'),
                'cumul_repris'   => (int) $c->opening_accumulated_depreciation,
                'dotation'       => (int) $c->annual_depreciation,
            ])->values()->all(),
            'works' => $property->works->map(fn ($w) => [
                'libelle'        => $w->description,
                'date'           => $w->work_date?->format('Y-m-d'),
                'montant_ttc'    => (int) $w->amount,
                'montant_ht'     => (int) $w->amount_ht,
                'duree_ans'      => (int) $w->duration_years,
                'ligne_2033c'    => $w->cerfa_category,
                'source_dotation' => $w->depreciation_source,
                'cumul_repris'   => (int) $w->opening_accumulated_depreciation,
            ])->values()->all(),
            'furniture' => $property->furniture->map(fn ($f) => [
                'libelle'        => $f->description,
                'date'           => $f->purchase_date?->format('Y-m-d'),
                'montant_ttc'    => (int) $f->amount,
                'montant_ht'     => (int) $f->amount_ht,
                'duree_ans'      => (int) $f->duration_years,
                'ligne_2033c'    => $f->cerfa_category,
                'source_dotation' => $f->depreciation_source,
                'cumul_repris'   => (int) $f->opening_accumulated_depreciation,
            ])->values()->all(),
            // Le détail calculé, celui-là même qui alimente le 2033-A et le 2033-C.
            'detail' => $this->depreciationService->depreciationDetailForYear($property, $year),
        ];
    }

    /**
     * Le rapport en texte brut, prêt à coller dans un ticket ou une issue.
     *
     * Du texte plutôt que du JSON : c'est ce qu'un humain relit avant d'envoyer, et la
     * relecture est la seule garantie qu'il ne transmet rien qu'il ne voulait pas.
     */
    public function toText(array $report): string
    {
        $euro = fn (?int $cents) => $cents === null ? '—' : number_format($cents / 100, 2, ',', ' ') . ' €';
        $out = [];

        $out[] = '=== RAPPORT DE DIAGNOSTIC OPENLMNP ===';
        $out[] = sprintf('Format %d · généré le %s · exercice %d',
            $report['schema_version'], $report['generated_at'], $report['year']);
        $out[] = sprintf('OpenLMNP %s · PHP %s · Laravel %s · base %s · installation %s',
            $report['environment']['app_version'],
            $report['environment']['php_version'],
            $report['environment']['laravel_version'],
            $report['environment']['db_driver'],
            $report['environment']['install_type'],
        );
        $out[] = 'Aucune donnée nominative : ni nom, ni adresse, ni commune, ni adresse e-mail.';
        $out[] = '';

        foreach ($report['properties'] as $property) {
            $in = $property['inputs'];

            $out[] = "--- {$property['label']} ---";
            $out[] = 'Prix d\'acquisition ......... ' . $euro($in['prix_acquisition']);
            $out[] = 'Valeur vénale .............. ' . $euro($in['valeur_venale']);
            $out[] = 'Valeur de référence retenue  ' . $euro($in['valeur_reference']);
            $out[] = sprintf('Part du terrain ............ %d %%', $in['part_terrain_pct']);
            $out[] = sprintf('Surfaces ................... %d m² loués / %d m² → quote-part %s',
                $in['surface_louee'], $in['surface_totale'], $in['quote_part']);
            $out[] = 'Résidence principale ....... ' . ($in['residence_principale'] ? 'oui' : 'non');
            $out[] = 'Mise en location ........... ' . ($in['date_mise_en_location'] ?? '—');
            $out[] = 'Régime TVA ................. ' . ($in['regime_tva'] ?? '—');
            $out[] = 'Frais notaire / agence ..... ' . $euro($in['frais_notaire']) . ' / ' . $euro($in['frais_agence']);
            $out[] = sprintf('Traitement des frais ....... %s (sur %d ans)',
                $in['traitement_frais'], $in['duree_frais_ans']);

            if ($in['frais_ecartes_par_valeur_venale']) {
                $out[] = '  ⚠ Frais capitalisés IGNORÉS : une valeur vénale sert de référence.';
            }

            $out[] = 'BASE AMORTISSABLE .......... ' . $euro($property['base_amortissable']);
            $out[] = '';

            $out[] = 'Composants :';
            $out[] = $this->table(
                ['Nom', 'Ligne', 'Base', 'Part', 'Durée', 'Source', 'Départ', 'Cumul repris'],
                array_map(fn (array $c) => [
                    $c['nom'], $c['ligne_2033c'], $euro($c['base']), $c['part_pct'] . ' %',
                    $c['duree_ans'] . ' ans', $c['source_base'], $c['date_depart'] ?? '(bien)',
                    $euro($c['cumul_repris']),
                ], $property['components']),
            );

            foreach ([['works', 'Travaux'], ['furniture', 'Mobilier']] as [$key, $title]) {
                if ($property[$key] === []) {
                    continue;
                }

                $out[] = "{$title} :";
                $out[] = $this->table(
                    ['Libellé', 'Date', 'TTC', 'HT', 'Durée', 'Ligne', 'Source', 'Cumul repris'],
                    array_map(fn (array $a) => [
                        $a['libelle'], $a['date'] ?? '—', $euro($a['montant_ttc']), $euro($a['montant_ht']),
                        $a['duree_ans'] . ' ans', $a['ligne_2033c'] ?? '(défaut)',
                        $a['source_dotation'] ?? '—', $euro($a['cumul_repris']),
                    ], $property[$key]),
                );
            }

            $out[] = 'Détail calculé de l\'exercice :';
            $out[] = $this->table(
                ['Actif', 'Type', 'Ligne', 'Brut', 'Dotation', 'Cumul'],
                array_map(fn (array $l) => [
                    $l['name'], $l['type'], $l['cerfa_category'],
                    $euro((int) $l['base']), $euro((int) $l['annual']), $euro((int) $l['cumul']),
                ], $property['detail']),
            );
            $out[] = '';
        }

        if (! isset($report['forms'])) {
            $out[] = 'Aucun bien enregistré : pas de liasse à décrire.';

            return implode("\n", $out);
        }

        $out[] = '--- 2033-A (bilan) ---';
        foreach ($report['forms']['2033A'] as $line => $value) {
            $out[] = sprintf('  case %-4s %s', $line, $euro((int) $value));
        }
        $out[] = '';

        $out[] = '--- 2033-C, cadre I (immobilisations) ---';
        $out[] = $this->table(
            ['Catégorie', 'Lignes', 'Brut', 'Dotation', 'Cumul'],
            array_map(fn (string $name, array $cat) => [
                $name,
                $cat['lines']['immo'] . ($cat['lines']['amort'] ? ' / ' . $cat['lines']['amort'] : ''),
                $euro($cat['brut']), $euro($cat['dotation']), $euro($cat['cumul']),
            ], array_keys($report['forms']['2033C']['categories']), $report['forms']['2033C']['categories']),
        );
        $out[] = sprintf('  TOTAL 490/572/570 : %s · %s · %s',
            $euro($report['forms']['2033C']['total_brut']),
            $euro($report['forms']['2033C']['total_dotation']),
            $euro($report['forms']['2033C']['total_cumul']),
        );
        $out[] = '';

        $out[] = '--- Écarts ---';
        $out[] = '  044 − 490 ............... ' . $euro($report['gaps']['044_moins_490'])
            . '  (doit valoir la part non ventilée)';
        $out[] = '  572 − 254 ............... ' . $euro($report['gaps']['572_moins_254'])
            . '  (doit valoir zéro)';
        $out[] = '  base − Σ composants ..... ' . $euro($report['gaps']['base_moins_composants']);
        $out[] = '';

        $out[] = '--- Contrôles ---';
        foreach ($report['checks'] as $check) {
            $out[] = sprintf('  [%s] %s', strtoupper($check['status']), $check['message']);
        }

        return implode("\n", $out);
    }

    /**
     * Un tableau à colonnes alignées.
     *
     * Aligné et non séparé par des tabulations : le rapport est collé dans un ticket ou une
     * issue GitHub, où les tabulations sont ré-interprétées et la colonne se perd.
     *
     * @param  list<string>        $headers
     * @param  list<list<string>>  $rows
     */
    private function table(array $headers, array $rows): string
    {
        if ($rows === []) {
            return '  (aucun)';
        }

        $widths = array_map(
            fn (int $i) => max(array_map(
                fn (array $r) => mb_strlen((string) ($r[$i] ?? '')),
                [...$rows, $headers],
            )),
            array_keys($headers),
        );

        $line = fn (array $cells) => '  ' . implode('  ', array_map(
            fn (int $i) => mb_str_pad((string) ($cells[$i] ?? ''), $widths[$i]),
            array_keys($headers),
        ));

        return implode("\n", [
            $line($headers),
            '  ' . implode('  ', array_map(fn (int $w) => str_repeat('-', $w), $widths)),
            ...array_map($line, $rows),
        ]);
    }
}
