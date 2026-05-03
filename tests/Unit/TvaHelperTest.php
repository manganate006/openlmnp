<?php

use App\Helpers\TvaHelper;

it('returns ht = ttc and tva = 0 when rate is exempt', function () {
    $result = TvaHelper::fromTtc(12000, 0);

    expect($result['ht'])->toBe(12000);
    expect($result['tva'])->toBe(0);
});

it('calculates 20% TVA from TTC correctly', function () {
    // 120 EUR TTC at 20% → 100 EUR HT + 20 EUR TVA
    $result = TvaHelper::fromTtc(12000, 2000);

    expect($result['ht'])->toBe(10000);
    expect($result['tva'])->toBe(2000);
});

it('calculates 10% TVA from TTC correctly', function () {
    // 110 EUR TTC at 10% → 100 EUR HT + 10 EUR TVA
    $result = TvaHelper::fromTtc(11000, 1000);

    expect($result['ht'])->toBe(10000);
    expect($result['tva'])->toBe(1000);
});

it('calculates 5.5% TVA from TTC correctly', function () {
    // 1055 EUR TTC at 5.5% → 1000 EUR HT + 55 EUR TVA
    $result = TvaHelper::fromTtc(105500, 550);

    expect($result['ht'])->toBe(100000);
    expect($result['tva'])->toBe(5500);
});

it('handles zero amount', function () {
    $result = TvaHelper::fromTtc(0, 2000);

    expect($result['ht'])->toBe(0);
    expect($result['tva'])->toBe(0);
});

it('ensures ht + tva = ttc (no rounding leak)', function () {
    // Montant qui ne tombe pas rond
    $result = TvaHelper::fromTtc(9999, 2000);

    expect($result['ht'] + $result['tva'])->toBe(9999);
});

it('calculates TTC from HT correctly', function () {
    $result = TvaHelper::fromHt(10000, 2000);

    expect($result['ttc'])->toBe(12000);
    expect($result['tva'])->toBe(2000);
});

it('returns ttc = ht when rate is exempt for fromHt', function () {
    $result = TvaHelper::fromHt(10000, 0);

    expect($result['ttc'])->toBe(10000);
    expect($result['tva'])->toBe(0);
});
