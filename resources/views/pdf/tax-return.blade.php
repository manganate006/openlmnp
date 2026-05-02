<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Liasse fiscale LMNP {{ $year }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #333; margin: 15px; }
        h1 { font-size: 16px; color: #065f46; border-bottom: 2px solid #065f46; padding-bottom: 4px; margin-bottom: 10px; }
        h2 { font-size: 13px; color: #065f46; margin-top: 15px; border-bottom: 1px solid #d1d5db; padding-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 9px; }
        th, td { border: 1px solid #d1d5db; padding: 3px 6px; }
        th { background: #f3f4f6; font-weight: bold; text-align: center; }
        .r { text-align: right; font-family: monospace; }
        .c { text-align: center; }
        .total { background: #ecfdf5; font-weight: bold; }
        .header { background: #f0fdf4; border: 1px solid #86efac; padding: 8px; margin-bottom: 12px; }
        .page-break { page-break-before: always; }
        .small { font-size: 7px; color: #6b7280; }
        .result-box { background: #ecfdf5; border: 2px solid #10b981; padding: 12px; text-align: center; margin: 10px 0; }
        .result-box .amount { font-size: 20px; font-weight: bold; color: #065f46; }
        .line-num { color: #9ca3af; font-size: 8px; width: 35px; }
        .warn { color: #dc2626; font-weight: bold; }
    </style>
</head>
<body>
    @php
        $fmt = fn($cents) => number_format($cents / 100, 2, ',', ' ');
        $fmtInt = fn($cents) => number_format($cents / 100, 0, ',', ' ');
    @endphp

    <div class="header">
        <h1>Liasse fiscale LMNP — Exercice {{ $year }}</h1>
        <table style="border:none;">
            <tr style="border:none;"><td style="border:none;width:50%;"><strong>{{ $user->name }}</strong></td><td style="border:none;">SIREN : {{ $siren }}</td></tr>
            <tr style="border:none;"><td style="border:none;">Régime : BIC Réel Simplifié (RSI)</td><td style="border:none;">Exercice : 01/01/{{ $year }} au 31/12/{{ $year }}</td></tr>
            <tr style="border:none;"><td style="border:none;">Activité : Location meublée non professionnelle</td><td style="border:none;">{{ count($properties) }} bien(s)</td></tr>
        </table>
        <p class="small">Document généré le {{ now()->format('d/m/Y à H:i') }} par OpenLMNP — Aide à la déclaration, ne se substitue pas aux formulaires Cerfa officiels.</p>
    </div>

    {{-- RÉSULTAT PRINCIPAL --}}
    <div class="result-box">
        <p>Résultat fiscal {{ $year }}</p>
        <p class="amount">{{ $fmt($fiscalYear->fiscal_result) }} €</p>
        <p class="small">
            @if($form2042['is_benefice'])
                Bénéfice → Case <strong>{{ $form2042['case_benefice'] }}</strong> de la 2042-C-PRO : {{ $fmtInt($form2042['montant']) }} €
            @else
                Déficit → Case <strong>{{ $form2042['case_deficit'] }}</strong> de la 2042-C-PRO : {{ $fmtInt($form2042['montant']) }} €
            @endif
        </p>
    </div>

    {{-- 2033-A : BILAN SIMPLIFIÉ --}}
    <h2>Formulaire 2033-A — Bilan simplifié</h2>
    <table>
        <tr><th colspan="2" class="c">ACTIF</th><th colspan="2" class="c">PASSIF</th></tr>
        <tr>
            <td>Immob. corporelles brut (028)</td><td class="r">{{ $fmtInt($form2033A['028']) }} €</td>
            <td>Compte exploitant (120)</td><td class="r">{{ $fmtInt($form2033A['120']) }} €</td>
        </tr>
        <tr>
            <td>Amortissements (030)</td><td class="r">- {{ $fmtInt($form2033A['030']) }} €</td>
            <td>Résultat exercice (136)</td><td class="r">{{ $fmt($form2033A['136']) }} €</td>
        </tr>
        <tr>
            <td></td><td></td>
            <td>Emprunts (156)</td><td class="r">{{ $fmtInt($form2033A['156']) }} €</td>
        </tr>
        <tr class="total">
            <td><strong>Total actif (112)</strong></td><td class="r"><strong>{{ $fmtInt($form2033A['112']) }} €</strong></td>
            <td><strong>Total passif (180)</strong></td><td class="r"><strong>{{ $fmtInt($form2033A['180']) }} €</strong></td>
        </tr>
    </table>

    {{-- 2033-B : COMPTE DE RÉSULTAT --}}
    <h2>Formulaire 2033-B — Compte de résultat simplifié</h2>
    <table>
        <tr><th class="line-num">Ligne</th><th>Désignation</th><th class="r" style="width:120px;">Montant</th></tr>
        <tr><td class="c">218</td><td>Production vendue — Services (loyers nets)</td><td class="r">{{ $fmt($form2033B['218']) }} €</td></tr>
        <tr class="total"><td class="c">232</td><td><strong>Total produits d'exploitation (I)</strong></td><td class="r"><strong>{{ $fmt($form2033B['232']) }} €</strong></td></tr>
        <tr><td class="c">242</td><td>Autres charges externes (assurance, entretien, commissions, compta, télécom...)</td><td class="r">{{ $fmt($form2033B['242']) }} €</td></tr>
        <tr><td class="c">244</td><td>Impôts, taxes (taxe foncière, CFE)</td><td class="r">{{ $fmt($form2033B['244']) }} €</td></tr>
        <tr><td class="c">254</td><td>Dotations aux amortissements</td><td class="r">{{ $fmt($form2033B['254']) }} €</td></tr>
        <tr class="total"><td class="c">264</td><td><strong>Total charges d'exploitation (II)</strong></td><td class="r"><strong>{{ $fmt($form2033B['264']) }} €</strong></td></tr>
        <tr><td class="c">270</td><td>Résultat d'exploitation (I — II)</td><td class="r">{{ $fmt($form2033B['270']) }} €</td></tr>
        <tr><td class="c">294</td><td>Charges financières (intérêts emprunt)</td><td class="r">{{ $fmt($form2033B['294']) }} €</td></tr>
        <tr class="total"><td class="c">310</td><td><strong>Résultat comptable</strong></td><td class="r"><strong>{{ $fmt($form2033B['310']) }} €</strong></td></tr>
    </table>

    <h2 style="font-size:11px;">Détermination du résultat fiscal (2033-B partie B)</h2>
    <table>
        <tr><td class="c">312/314</td><td>Résultat comptable (bénéfice / déficit)</td><td class="r">{{ $fmt($form2033B['312']) }} € / {{ $fmt($form2033B['314']) }} €</td></tr>
        <tr><td class="c">318</td><td>Amortissements réputés différés (art. 39C) — excédent non déduit</td><td class="r">{{ $fmt($form2033B['318']) }} €</td></tr>
        <tr><td class="c">352/354</td><td>Résultat fiscal avant imputation déficits</td><td class="r">{{ $fmt($form2033B['352']) }} € / {{ $fmt($form2033B['354']) }} €</td></tr>
        <tr><td class="c">360</td><td>Déficits antérieurs reportables imputés</td><td class="r">{{ $fmt($form2033B['360']) }} €</td></tr>
        <tr class="total"><td class="c">370/372</td><td><strong>Résultat fiscal après imputation</strong></td><td class="r"><strong>{{ $fmt($form2033B['370']) }} € / {{ $fmt($form2033B['372']) }} €</strong></td></tr>
    </table>

    {{-- 2033-C : IMMOBILISATIONS ET AMORTISSEMENTS --}}
    <div class="page-break"></div>
    <h2>Formulaire 2033-C — Immobilisations et amortissements</h2>

    <h2 style="font-size:10px;">Cadre I — Immobilisations (valeurs brutes)</h2>
    <table>
        <tr><th class="line-num">Ligne</th><th>Catégorie</th><th class="r">Valeur brute</th><th class="r">Dotation annuelle</th></tr>
        @foreach($form2033C['categories'] as $catName => $cat)
            @if($cat['brut'] > 0)
                <tr>
                    <td class="c">{{ $cat['lines']['immo'] }}</td>
                    <td>{{ ucfirst($catName) }}</td>
                    <td class="r">{{ $fmtInt($cat['brut']) }} €</td>
                    <td class="r">{{ $fmtInt($cat['dotation']) }} €</td>
                </tr>
            @endif
        @endforeach
        <tr class="total">
            <td class="c">490/572</td>
            <td><strong>TOTAL</strong></td>
            <td class="r"><strong>{{ $fmtInt($form2033C['total_brut']) }} €</strong></td>
            <td class="r"><strong>{{ $fmtInt($form2033C['total_dotation']) }} €</strong></td>
        </tr>
    </table>

    <p class="small">
        @if($form2033C['total_dotation'] != $form2033B['254'])
            <span class="warn">⚠ Écart : ligne 572 ({{ $fmtInt($form2033C['total_dotation']) }} €) ≠ ligne 254 du 2033-B ({{ $fmtInt($form2033B['254']) }} €)</span>
        @else
            ✓ Cohérence vérifiée : ligne 572 = ligne 254 du 2033-B ({{ $fmtInt($form2033C['total_dotation']) }} €)
        @endif
    </p>

    {{-- 2033-D : DÉFICITS --}}
    <h2>Formulaire 2033-D — Déficits reportables</h2>
    <table>
        <tr><td class="c">982</td><td>Déficits restant à reporter N-1</td><td class="r">{{ $fmt($form2033D['982']) }} €</td></tr>
        <tr><td class="c">983</td><td>Déficits imputés</td><td class="r">{{ $fmt($form2033D['983']) }} €</td></tr>
        <tr><td class="c">984</td><td>Déficits reportables (non imputés)</td><td class="r">{{ $fmt($form2033D['984']) }} €</td></tr>
        <tr><td class="c">860</td><td>Déficit de l'exercice</td><td class="r">{{ $fmt($form2033D['860']) }} €</td></tr>
        <tr class="total"><td class="c">870</td><td><strong>Total déficits restant à reporter</strong></td><td class="r"><strong>{{ $fmt($form2033D['870']) }} €</strong></td></tr>
    </table>

    {{-- 2042-C-PRO --}}
    <h2>Report sur la déclaration de revenus 2042-C-PRO</h2>
    <table>
        <tr>
            <td>
                @if($form2042['is_benefice'])
                    <strong>Case {{ $form2042['case_benefice'] }}</strong> (bénéfice LMNP)
                @else
                    <strong>Case {{ $form2042['case_deficit'] }}</strong> (déficit LMNP)
                @endif
            </td>
            <td class="r"><strong>{{ $fmtInt($form2042['montant']) }} €</strong></td>
        </tr>
    </table>
    <p class="small">
        5NA = bénéfice avec adhésion OGA | 5NK = bénéfice sans OGA (même effet fiscal depuis 2023)<br>
        5NY = déficit avec OGA | 5NZ = déficit sans OGA
    </p>

    {{-- ANNEXE --}}
    <div class="page-break"></div>
    <h2>Annexe — Détail des biens immobiliers</h2>
    @foreach($properties as $property)
        <h2 style="font-size:10px;">{{ $property->name }}</h2>
        <table>
            <tr><td style="width:40%;">Adresse</td><td>{{ $property->address }}, {{ $property->postal_code }} {{ $property->city }}</td></tr>
            <tr><td>Surface totale / louée</td><td>{{ $property->total_area }} m² / {{ $property->rented_area }} m² (quote-part : {{ number_format((float) $property->quota_share * 100, 1) }}%)</td></tr>
            <tr><td>Valeur de référence</td><td>{{ $fmtInt($property->market_value ?? $property->acquisition_price) }} €</td></tr>
            <tr><td>Base amortissable</td><td>{{ $fmtInt((int) $property->depreciable_base) }} €</td></tr>
            <tr><td>Résidence principale</td><td>{{ $property->is_primary_residence ? 'Oui' : 'Non' }}</td></tr>
        </table>
    @endforeach

    <div style="margin-top: 20px; border-top: 1px solid #d1d5db; padding-top: 8px;">
        <p class="small">
            Ce document est un récapitulatif structuré selon les lignes des formulaires Cerfa 2031-SD, 2033-A/B/C/D.
            Il ne constitue pas une liasse fiscale officielle. Pour la déclaration, utilisez les formulaires Cerfa sur impots.gouv.fr
            ou transmettez via un logiciel agréé EDI-TDFC. OpenLMNP v0.1 — Logiciel libre AGPLv3.
        </p>
    </div>
</body>
</html>
