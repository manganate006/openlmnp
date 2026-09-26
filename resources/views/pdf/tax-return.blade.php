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
        .notice { color: #b45309; font-weight: bold; }
        .keep { page-break-inside: avoid; }
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

    {{-- 2031-SD : DÉCLARATION DE RÉSULTAT.
         L'écran de télédéclaration l'affichait depuis toujours, et `$form2031` était passé à
         cette vue sans qu'elle l'utilise : le document annonçait donc un formulaire qu'il ne
         contenait pas. `TaxReturnFormsTest` ancre désormais l'annonce au document. --}}
    <h2>Formulaire 2031-SD — Déclaration de résultat</h2>
    <table>
        <tr><td class="c">AB</td><td>Production vendue — Services (loyers)</td><td class="r">{{ $fmt($form2031['AB']) }} €</td></tr>
        <tr><td class="c">CB</td><td>Bénéfice fiscal</td><td class="r">{{ $fmt($form2031['CB']) }} €</td></tr>
        <tr><td class="c">CC</td><td>Déficit fiscal</td><td class="r">{{ $fmt($form2031['CC']) }} €</td></tr>
    </table>

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
            <td>Immob. incorporelles brut (014)</td><td class="r">{{ $fmtInt($form2033A['014']) }} €</td>
            <td>Emprunts (156)</td><td class="r">{{ $fmtInt($form2033A['156']) }} €</td>
        </tr>
        <tr>
            <td>Amortissements (016)</td><td class="r">- {{ $fmtInt($form2033A['016']) }} €</td>
            <td></td><td></td>
        </tr>
        {{-- 044 et 048 étaient calculées sans être affichées nulle part : un contrôle qui
             nomme « case 044 » doit pouvoir se lire ici. --}}
        <tr>
            <td>Total immob. brut (044)</td><td class="r">{{ $fmtInt($form2033A['044']) }} €</td>
            <td></td><td></td>
        </tr>
        <tr>
            <td>Total amortissements (048)</td><td class="r">- {{ $fmtInt($form2033A['048']) }} €</td>
            <td></td><td></td>
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
        <tr><td class="c">318</td><td>Réintégration : amortissements de l'exercice non déductibles (art. 39 C), mis en report</td><td class="r">{{ $fmt($form2033B['318']) }} €</td></tr>
        <tr><td class="c">350</td><td>Déduction : amortissements différés des exercices antérieurs repris (art. 39 C)</td><td class="r">{{ $fmt($form2033B['350']) }} €</td></tr>
        <tr><td class="c">352/354</td><td>Résultat fiscal avant imputation des déficits antérieurs</td><td class="r">{{ $fmt($form2033B['352']) }} € / {{ $fmt($form2033B['354']) }} €</td></tr>
        <tr><td class="c">360</td><td>Déficits antérieurs imputés</td><td class="r">{{ $fmt($form2033B['360']) }} €</td></tr>
        <tr class="total"><td class="c">370/372</td><td><strong>Résultat fiscal après imputation</strong></td><td class="r"><strong>{{ $fmt($form2033B['370']) }} € / {{ $fmt($form2033B['372']) }} €</strong></td></tr>
    </table>

    {{-- ANNEXE : d'où vient chaque ligne du 2033-B (issue #13).
         Même source que le tableau ci-dessus (TaxReturnService::resultBreakdown()), donc
         jamais en désaccord avec lui. Pas un formulaire Cerfa : titre « Annexe — », que
         `TaxReturnFormsTest` distingue des sections « Formulaire … ». --}}
    @php
        $pct = fn (string $q) => rtrim(rtrim(number_format((float) bcmul($q, '100', 4), 2, ',', ' '), '0'), ',') . ' %';
        $rb = $resultBreakdown;
        $cap = $rb['capping'];
        $def = $rb['deficits'];
    @endphp
    <h2>Annexe — Détail du résultat</h2>
    <p class="small">
        Ce détail n'est pas à recopier sur votre déclaration : il permet de refaire à la main
        chaque ligne du 2033-B ci-dessus. Pour un bien assujetti à la TVA, les montants sont
        hors taxe. Une charge « partagée » n'est retenue qu'à hauteur de la quote-part
        locative du bien, appliquée au total des charges partagées (et non charge par charge).
    </p>

    @foreach($rb['properties'] as $prop)
        @php $t = $prop['expense_totals']; @endphp
        <h2 style="font-size:11px;">{{ $prop['name'] }} — quote-part locative {{ $pct($prop['quota_share']) }}@if($prop['tva_liable']) — montants HT @endif</h2>

        <table>
            <tr><th colspan="2">Recettes (ligne 218)</th><th class="r" style="width:120px;">Montant</th></tr>
            <tr><td colspan="2">Recettes encaissées ({{ $prop['income']['count'] }} ligne{{ $prop['income']['count'] > 1 ? 's' : '' }})</td><td class="r">{{ $fmt($prop['income']['gross']) }} €</td></tr>
            <tr><td colspan="2">Commissions des plateformes déduites</td><td class="r">− {{ $fmt($prop['income']['fees']) }} €</td></tr>
            <tr class="total"><td colspan="2">Loyers nets retenus</td><td class="r">{{ $fmt($prop['income']['net']) }} €</td></tr>
        </table>

        @if(count($prop['expenses']) > 0)
            <table>
                <tr>
                    <th style="width:60px;">Date</th>
                    <th>Charge</th>
                    <th>Catégorie</th>
                    <th class="line-num">Ligne</th>
                    <th class="c">Affectation</th>
                    <th class="r">Montant</th>
                </tr>
                @foreach($prop['expenses'] as $exp)
                    <tr>
                        <td>{{ $exp['date'] ? \Illuminate\Support\Carbon::parse($exp['date'])->format('d/m/Y') : '' }}</td>
                        <td>{{ $exp['description'] }}</td>
                        <td>{{ $exp['category'] }}</td>
                        <td class="c">{{ $exp['line'] }}</td>
                        <td class="c">{{ $exp['dedicated'] ? 'Dédiée (100 %)' : 'Partagée' }}</td>
                        <td class="r">{{ $fmt($exp['amount']) }} €</td>
                    </tr>
                @endforeach
            </table>
        @endif

        <table>
            <tr><th>Charges retenues</th><th class="r" style="width:90px;">Ligne 242</th><th class="r" style="width:90px;">Ligne 244</th></tr>
            <tr><td>Charges dédiées, retenues en totalité</td><td class="r">{{ $fmt($t['dedicated_242']) }} €</td><td class="r">{{ $fmt($t['dedicated_244']) }} €</td></tr>
            <tr><td>Charges partagées, avant quote-part</td><td class="r">{{ $fmt($t['shared_242']) }} €</td><td class="r">{{ $fmt($t['shared_244']) }} €</td></tr>
            <tr><td>Charges partagées retenues : {{ $fmt($t['shared_total']) }} € × {{ $pct($prop['quota_share']) }} = {{ $fmt($t['shared_retained']) }} €</td><td class="r">{{ $fmt($t['shared_retained_242']) }} €</td><td class="r">{{ $fmt($t['shared_retained_244']) }} €</td></tr>
            <tr class="total"><td>Total retenu</td><td class="r">{{ $fmt($t['retained_242']) }} €</td><td class="r">{{ $fmt($t['retained_244']) }} €</td></tr>
        </table>

        @if(count($prop['loans']) > 0)
            <table>
                <tr><th>Emprunt (ligne 294)</th><th class="r">Intérêts</th><th class="r">Assurance</th><th class="r">Retenu ({{ $pct($prop['quota_share']) }})</th></tr>
                @foreach($prop['loans'] as $loan)
                    <tr>
                        <td>{{ $loan['name'] }}</td>
                        <td class="r">{{ $fmt($loan['interest']) }} €</td>
                        <td class="r">{{ $fmt($loan['insurance']) }} €</td>
                        <td class="r">{{ $fmt($loan['interest_retained']) }} € + {{ $fmt($loan['insurance_retained']) }} € = {{ $fmt($loan['retained']) }} €</td>
                    </tr>
                @endforeach
            </table>
        @endif

        <p class="small">Dotation aux amortissements {{ $year }} (ligne 254) : {{ $fmt($prop['depreciation']) }} € — détail actif par actif dans l'annexe des immobilisations.</p>
    @endforeach

    <div class="keep">
    <h2 style="font-size:11px;">Plafonnement des amortissements (art. 39 C du CGI)</h2>
    <p class="small">
        L'amortissement ne peut pas créer ni augmenter un déficit : il n'est déduit que dans la
        limite du résultat avant amortissement. Ce qui dépasse est mis en report, sans limite
        de durée, et repris les années suivantes.
    </p>
    <table>
        <tr><td>Recettes retenues</td><td class="r" style="width:120px;">{{ $fmt($cap['income']) }} €</td></tr>
        <tr><td>− Charges retenues (charges et emprunts, quote-part appliquée)</td><td class="r">{{ $fmt($cap['expenses']) }} €</td></tr>
        <tr class="total"><td>= Résultat avant amortissements (plafond)</td><td class="r">{{ $fmt($cap['result_before_depreciation']) }} €</td></tr>
        <tr><td>Dotation de l'exercice (ligne 254)</td><td class="r">{{ $fmt($cap['depreciation_year']) }} €</td></tr>
        <tr><td>+ Amortissements différés des exercices antérieurs</td><td class="r">{{ $fmt($cap['carried_forward']) }} €</td></tr>
        <tr><td>= Amortissements disponibles</td><td class="r">{{ $fmt($cap['available']) }} €</td></tr>
        <tr class="total"><td>Amortissements déduits (dans la limite du plafond)</td><td class="r">{{ $fmt($cap['deducted']) }} €</td></tr>
        <tr><td>&nbsp;&nbsp;dont dotation de l'exercice</td><td class="r">{{ $fmt($cap['deducted_current']) }} €</td></tr>
        <tr><td>&nbsp;&nbsp;dont reports antérieurs repris (ligne 350)</td><td class="r">{{ $fmt($cap['deducted_carried']) }} €</td></tr>
        <tr><td>Dotation de l'exercice non déduite, réintégrée (ligne 318)</td><td class="r">{{ $fmt($cap['reintegrated']) }} €</td></tr>
        <tr><td>Amortissements différés à reporter à la clôture (2033-D, case 870)</td><td class="r">{{ $fmt($cap['deferred']) }} €</td></tr>
        <tr class="total"><td>Résultat fiscal avant imputation des déficits (352/354)</td><td class="r">{{ $fmt($cap['fiscal_result']) }} €</td></tr>
    </table>

    </div>

    <div class="keep">
    <h2 style="font-size:11px;">Déficits antérieurs</h2>
    <p class="small">
        Un déficit ne s'impute que sur un bénéfice de même nature, pendant dix ans, le plus
        ancien d'abord. Il s'impute après les amortissements.
    </p>
    @if(count($def['detail']) > 0)
        <table>
            <tr><th>Millésime</th><th class="r">À l'ouverture</th><th class="r">Imputé</th><th class="r">Périmé</th><th class="r">Restant</th></tr>
            @foreach($def['detail'] as $vintage)
                <tr>
                    <td class="c">{{ $vintage['origin_year'] ?? '' }}</td>
                    <td class="r">{{ $fmt((int) ($vintage['opening'] ?? 0)) }} €</td>
                    <td class="r">{{ $fmt((int) ($vintage['imputed'] ?? 0)) }} €</td>
                    <td class="r">{{ $fmt((int) ($vintage['expired'] ?? 0)) }} €</td>
                    <td class="r">{{ $fmt((int) ($vintage['remaining'] ?? 0)) }} €</td>
                </tr>
            @endforeach
        </table>
    @endif
    <table>
        <tr><td>Déficits antérieurs à l'ouverture</td><td class="r" style="width:120px;">{{ $fmt($def['previous']) }} €</td></tr>
        <tr><td>Imputés sur le résultat de l'exercice (ligne 360)</td><td class="r">{{ $fmt($def['imputed']) }} €</td></tr>
        <tr class="total"><td>Restant à reporter à la clôture</td><td class="r">{{ $fmt($def['carryforward']) }} €</td></tr>
    </table>
    </div>

    {{-- 2033-C : IMMOBILISATIONS ET AMORTISSEMENTS --}}
    <div class="page-break"></div>
    <h2>Formulaire 2033-C — Immobilisations et amortissements</h2>

    <h2 style="font-size:10px;">Cadre I — Immobilisations (valeurs brutes)</h2>
    <table>
        <tr>
            <th class="line-num">Ligne</th>
            <th>Catégorie</th>
            <th class="r">Valeur brute</th>
            <th class="r">Dotation annuelle</th>
            <th class="r">Amort. à la fin de l'exercice</th>
        </tr>
        @foreach($form2033C['categories'] as $catName => $cat)
            @if($cat['brut'] > 0)
                <tr>
                    {{-- Le terrain n'a pas de ligne d'amortissement : il ne s'amortit pas. --}}
                    <td class="c">{{ $cat['lines']['immo'] }}@if($cat['lines']['amort']) / {{ $cat['lines']['amort'] }}@endif</td>
                    <td>{{ ucfirst($catName) }}</td>
                    <td class="r">{{ $fmtInt($cat['brut']) }} €</td>
                    <td class="r">{{ $fmtInt($cat['dotation']) }} €</td>
                    <td class="r">{{ $fmtInt($cat['cumul']) }} €</td>
                </tr>
            @endif
        @endforeach
        <tr class="total">
            <td class="c">490/572/570</td>
            <td><strong>TOTAL</strong></td>
            <td class="r"><strong>{{ $fmtInt($form2033C['total_brut']) }} €</strong></td>
            <td class="r"><strong>{{ $fmtInt($form2033C['total_dotation']) }} €</strong></td>
            <td class="r"><strong>{{ $fmtInt($form2033C['total_cumul']) }} €</strong></td>
        </tr>
    </table>

    {{-- Contrôles de cohérence : calculés par TaxReturnService::checks(), jamais ici.
         Une comparaison écrite dans la vue est une seconde règle qui dérive de la première. --}}
    @foreach($checks as $check)
        <p class="small">
            @if($check['status'] === \App\Services\TaxReturnService::CHECK_OK)
                ✓ {{ $check['message'] }}
            @elseif($check['status'] === \App\Services\TaxReturnService::CHECK_WARNING)
                <span class="notice">⚠ {{ $check['message'] }}</span>
            @else
                <span class="warn">⚠ {{ $check['message'] }}</span>
            @endif
        </p>
    @endforeach

    {{-- ANNEXE : d'où vient chaque montant du cadre I.
         Elle n'est PAS un formulaire Cerfa — d'où le titre sans numéro, que
         `TaxReturnFormsTest` distingue des sections « Formulaire … ». Elle existe parce
         qu'une ligne agrégée du 2033-C ne dit pas ce qu'elle contient : l'issue #11 a été
         ouverte par quelqu'un qui lisait 8 900 € en face d'un intitulé qui ne lui évoquait
         rien. Même source que les deux tableaux ci-dessus, donc jamais en désaccord. --}}
    <h2>Annexe — Détail des immobilisations</h2>
    <p class="small">
        Ce tableau n'est pas à recopier sur votre déclaration : il explique d'où vient chaque
        ligne du cadre I ci-dessus. Les composants d'immeuble ventilent la valeur du bien, ils
        ne s'y ajoutent pas — la colonne « Valeur brute » ne se totalise donc pas.
    </p>
    <table>
        <tr>
            <th>Immobilisation</th>
            <th>Origine</th>
            <th class="line-num">Ligne 2033-C</th>
            <th class="r">Valeur brute</th>
            <th class="r">Dotation {{ $year }}</th>
            <th class="r">Amort. cumulé</th>
        </tr>
        @foreach($assetBreakdown as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['origin'] }}</td>
                <td class="c">{{ $row['cerfa'] }}</td>
                <td class="r">{{ $fmtInt($row['base']) }} €</td>
                <td class="r">{{ $fmtInt($row['annual']) }} €</td>
                <td class="r">{{ $fmtInt($row['cumul']) }} €</td>
            </tr>
        @endforeach
    </table>

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
