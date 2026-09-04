<?php

use App\Services\OpenData\DvfClient;
use App\Services\OpenData\DvfEstimator;

/**
 * Moteur d'estimation DVF — aucune E/S, aucun réseau.
 *
 * Le test qui compte est « écarte les ventes multi-lots » : c'est le seul piège de DVF
 * capable de tripler un prix au m² sans que rien n'ait l'air anormal, et celui qui se
 * réintroduit tout seul si quelqu'un « optimise » le filtre pour élargir l'échantillon.
 */
const DVF_HEADER = 'id_mutation,date_mutation,nature_mutation,valeur_fonciere,code_postal,code_commune,nom_commune,code_departement,id_parcelle,nombre_lots,code_type_local,type_local,surface_reelle_bati,nombre_pieces_principales,surface_terrain';

/**
 * @param  array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5?: string}>  $rows
 *                                                                                                      [id_mutation, nature, valeur_fonciere, code_type_local, surface_bati, id_parcelle]
 */
function dvfCsv(array $rows): string
{
    $lines = [DVF_HEADER];

    foreach ($rows as $i => [$id, $nature, $value, $type, $area]) {
        $lines[] = implode(',', [
            $id, '2025-03-14', $nature, $value, '33000', '33063', 'Bordeaux', '33',
            $rows[$i][5] ?? ('33063000A'.$i), '1', $type,
            $type === '1' ? 'Maison' : 'Appartement', $area, '3', '',
        ]);
    }

    return implode("\n", $lines)."\n";
}

it('takes the median price per square meter of the commune', function () {
    // 4 000, 5 000 et 6 000 €/m² → médiane 5 000 €/m².
    $samples = DvfEstimator::samplesFromCsv(dvfCsv([
        ['A1', 'Vente', '200000.00', '2', '50'],
        ['A2', 'Vente', '250000.00', '2', '50'],
        ['A3', 'Vente', '300000.00', '2', '50'],
    ]));

    $result = DvfEstimator::estimate($samples, 'appartement', 40, [2025]);

    expect($result['sample_size'])->toBe(3)
        ->and($result['price_per_m2_cents'])->toBe(500_000)
        ->and($result['value_cents'])->toBe(20_000_000)
        ->and($result['years'])->toBe([2025]);
});

it('discards multi-lot sales, whose price would be attributed to a single lot', function () {
    $samples = DvfEstimator::samplesFromCsv(dvfCsv([
        ['M1', 'Vente', '800000.00', '2', '40'],
        ['M1', 'Vente', '800000.00', '2', '60'],
        ['M1', 'Vente', '800000.00', '2', '60'],
    ]));

    expect($samples)->toBe([]);
});

it('keeps a single dwelling spread over two land parcels', function () {
    // geo-dvf répète le même local sur chaque parcelle d'assiette : sans dédoublonnage,
    // toute maison à cheval sur deux parcelles serait écartée à tort comme multi-lots.
    $samples = DvfEstimator::samplesFromCsv(dvfCsv([
        ['H1', 'Vente', '300000.00', '1', '100', '33063000A0001'],
        ['H1', 'Vente', '300000.00', '1', '100', '33063000A0002'],
    ]));

    expect($samples)->toHaveCount(1)
        ->and($samples[0]['p'])->toBe(300_000)
        ->and($samples[0]['t'])->toBe(DvfEstimator::TYPE_HOUSE);
});

it('filters the sample on the requested property type', function () {
    $samples = DvfEstimator::samplesFromCsv(dvfCsv([
        ['A1', 'Vente', '200000.00', '2', '50'],
        ['H1', 'Vente', '300000.00', '1', '100'],
        ['H2', 'Vente', '400000.00', '1', '100'],
    ]));

    expect(DvfEstimator::estimate($samples, 'appartement', 50, [2025])['sample_size'])->toBe(1)
        ->and(DvfEstimator::estimate($samples, 'maison', 100, [2025])['sample_size'])->toBe(2);
});

it('drops non-market transfers and implausible prices', function () {
    $samples = DvfEstimator::samplesFromCsv(dvfCsv([
        ['E1', 'Echange', '200000.00', '2', '50'],   // pas une vente
        ['E2', 'Vente', '1.00', '2', '50'],          // euro symbolique
        ['E3', 'Vente', '200000.00', '2', '0'],      // surface absente
        ['E4', 'Vente', '200000.00', '2', '5'],      // sous le seuil du logement
        ['E5', 'Vente', '200000.00', '4', '50'],     // local commercial
        ['E6', 'Vente', '9000000.00', '2', '20'],    // 450 000 €/m² : aberrant
        ['OK', 'Vente', '200000.00', '2', '50'],
    ]));

    expect($samples)->toHaveCount(1)
        ->and($samples[0]['p'])->toBe(400_000);
});

it('returns no value at all rather than a zero when the sample is empty', function () {
    $result = DvfEstimator::estimate([], 'appartement', 50, [2025]);

    expect($result['sample_size'])->toBe(0)
        ->and($result['price_per_m2_cents'])->toBeNull()
        ->and($result['value_cents'])->toBeNull();
});

it('averages the two middle values of an even sample', function () {
    expect(DvfEstimator::median([400_000, 500_000]))->toBe(450_000)
        ->and(DvfEstimator::median([400_000, 500_000, 600_000]))->toBe(500_000)
        ->and(DvfEstimator::median([]))->toBeNull();
});

it('clamps the area to plausible bounds', function () {
    $samples = DvfEstimator::samplesFromCsv(dvfCsv([['A1', 'Vente', '200000.00', '2', '50']]));

    expect(DvfEstimator::estimate($samples, 'appartement', -5, [2025])['area_m2'])->toBe(DvfEstimator::BOUNDS['area'][0])
        ->and(DvfEstimator::estimate($samples, 'appartement', 99_999, [2025])['area_m2'])->toBe(DvfEstimator::BOUNDS['area'][1]);
});

it('ignores a malformed file instead of failing', function () {
    expect(DvfEstimator::samplesFromCsv(''))->toBe([])
        ->and(DvfEstimator::samplesFromCsv("colonne_a,colonne_b\n1,2\n"))->toBe([]);
});

it('maps INSEE codes to the geo-dvf department directory', function () {
    // Trois caractères outre-mer, deux ailleurs — la Corse tombant juste (2A004 → 2A).
    expect(DvfClient::department('33063'))->toBe('33')
        ->and(DvfClient::department('2A004'))->toBe('2A')
        ->and(DvfClient::department('2B033'))->toBe('2B')
        ->and(DvfClient::department('97411'))->toBe('974')
        ->and(DvfClient::department('75111'))->toBe('75');
});
