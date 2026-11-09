{{--
    Lignes détaillées d'une proposition d'analyse.

    Trois formes selon le type de document :
      - mobilier   : un article amortissable par ligne, cochable ;
      - emprunt    : les échéances lues (lecture seule, contrôlées par ScheduleChecker) ;
      - relevé     : les opérations, cochables, avec catégorie modifiable.
--}}

@if($entity === 'furniture')
    <h4 style="font-size: 13px; font-weight: 700; margin: 20px 0 8px;">
        Articles détectés ({{ count($rows) }})
    </h4>
    <p style="font-size: 12px; color: var(--olmnp-fg-muted); margin: 0 0 8px;">
        Chaque article coché devient une ligne amortissable distincte. Décochez tout pour n'enregistrer
        qu'une seule ligne au montant total.
    </p>
    <div class="ai-scroll">
        <table class="ai-table">
            <thead>
                <tr>
                    <th style="width: 40px;"></th>
                    <th>Article</th>
                    <th class="num">Montant TTC</th>
                    <th class="num">Durée</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $index => $row)
                    <tr class="{{ $row['selected'] ? '' : 'muted' }}">
                        <td><input type="checkbox" wire:model.live="values.rows.{{ $index }}.selected"></td>
                        <td>{{ $row['description'] }}</td>
                        <td class="num">{{ number_format($row['amount'] / 100, 2, ',', "\u{202F}") }} €</td>
                        <td class="num">{{ $row['duration_years'] }} ans</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@elseif($entity === 'loan')
    <h4 style="font-size: 13px; font-weight: 700; margin: 20px 0 8px;">
        Échéances lues ({{ count($rows) }})
    </h4>
    <p style="font-size: 12px; color: var(--olmnp-fg-muted); margin: 0 0 8px;">
        Ces échéances remplacent le tableau théorique : ce sont elles qui alimenteront vos intérêts
        déductibles. Vous pourrez toujours revenir au tableau calculé depuis la fiche de l'emprunt.
    </p>
    <div class="ai-scroll">
        <table class="ai-table">
            <thead>
                <tr>
                    <th class="num">N°</th>
                    <th>Date</th>
                    <th class="num">Capital</th>
                    <th class="num">Intérêts</th>
                    <th class="num">Assurance</th>
                    <th class="num">Restant dû</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td class="num">{{ $row['month_number'] }}</td>
                        <td>{{ \Carbon\Carbon::parse($row['payment_date'])->format('d/m/Y') }}</td>
                        <td class="num">{{ number_format($row['capital_amount'] / 100, 2, ',', "\u{202F}") }} €</td>
                        <td class="num">{{ number_format($row['interest_amount'] / 100, 2, ',', "\u{202F}") }} €</td>
                        <td class="num">{{ number_format($row['insurance_amount'] / 100, 2, ',', "\u{202F}") }} €</td>
                        <td class="num">{{ number_format($row['remaining_capital'] / 100, 2, ',', "\u{202F}") }} €</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@elseif($entity === 'statement')
    <h4 style="font-size: 13px; font-weight: 700; margin: 20px 0 8px;">
        Opérations détectées ({{ count($rows) }})
    </h4>
    <p style="font-size: 12px; color: var(--olmnp-fg-muted); margin: 0 0 8px;">
        Seules les lignes cochées seront enregistrées. Les échéances de prêt ne sont jamais importées ici :
        elles relèvent du tableau d'amortissement, sous peine de compter deux fois le capital remboursé.
    </p>
    <div class="ai-scroll">
        <table class="ai-table">
            <thead>
                <tr>
                    <th style="width: 40px;"></th>
                    <th>Date</th>
                    <th>Libellé</th>
                    <th class="num">Montant</th>
                    <th>Nature</th>
                    <th>Catégorie</th>
                    <th style="width: 72px;">Dédiée</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $index => $row)
                    @php($importable = in_array($row['kind'], ['expense', 'income'], true) && empty($row['duplicate']))
                    <tr class="{{ $row['selected'] && $importable ? '' : 'muted' }}">
                        <td>
                            @if($importable)
                                <input type="checkbox" wire:model.live="values.rows.{{ $index }}.selected">
                            @endif
                        </td>
                        <td>{{ \Carbon\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                        <td>{{ $row['label'] }}</td>
                        <td class="num">{{ number_format($row['amount'] / 100, 2, ',', "\u{202F}") }} €</td>
                        <td>
                            @if(! empty($row['duplicate']))
                                Déjà saisie
                            @else
                                {{ match ($row['kind']) {
                                    'expense' => 'Charge',
                                    'income' => 'Recette',
                                    'loan_payment' => 'Échéance de prêt',
                                    default => 'Ignorée',
                                } }}
                            @endif
                        </td>
                        <td>
                            @if($row['kind'] === 'expense' && $importable)
                                <select class="ai-select" wire:model.live="values.rows.{{ $index }}.category">
                                    @foreach($this->categoryOptions() as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            @else
                                —
                            @endif
                        </td>
                        {{--
                            ⚠️ Cette colonne existait dans les données mais PAS à l'écran :
                            le normaliseur posait `is_dedicated` sur chaque ligne (d'après
                            `is_primary_residence` du bien) et l'import le consommait, sans
                            que personne puisse le corriger. C'était le seul chemin d'analyse
                            où l'utilisateur ne pouvait rien reprendre — or une valeur fausse
                            applique ou retire la quote-part de surface, donc change le
                            montant déduit.
                        --}}
                        <td>
                            @if($row['kind'] === 'expense' && $importable)
                                <input type="checkbox"
                                       wire:model.live="values.rows.{{ $index }}.is_dedicated"
                                       title="Cochée : la charge concerne uniquement le bien loué. Décochée : la quote-part de surface s'applique.">
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@elseif($entity === 'earnings')
    <h4 style="font-size: 13px; font-weight: 700; margin: 20px 0 8px;">
        Revenus mensuels ({{ count($rows) }})
    </h4>
    <p style="font-size: 12px; color: var(--olmnp-fg-muted); margin: 0 0 8px;">
        Une recette par mois, au dernier jour du mois. Le montant retenu est le <strong>brut</strong> ;
        la commission de la plateforme est enregistrée à part et déduite de votre résultat.
        Les mois sans location ne figurent pas dans cette liste.
    </p>
    <div class="ai-scroll">
        <table class="ai-table">
            <thead>
                <tr>
                    <th style="width: 40px;"></th>
                    <th>Mois</th>
                    <th class="num">Revenus bruts</th>
                    <th class="num">Commission</th>
                    <th class="num">Versé</th>
                    <th>État</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $index => $row)
                    @php($importable = empty($row['duplicate']))
                    <tr class="{{ $row['selected'] && $importable ? '' : 'muted' }}">
                        <td>
                            @if($importable)
                                <input type="checkbox" wire:model.live="values.rows.{{ $index }}.selected">
                            @endif
                        </td>
                        <td>{{ $row['label'] }}</td>
                        <td class="num">{{ number_format($row['amount'] / 100, 2, ',', "\u{202F}") }} €</td>
                        <td class="num">{{ number_format($row['platform_fee'] / 100, 2, ',', "\u{202F}") }} €</td>
                        <td class="num">{{ number_format(($row['amount'] - $row['platform_fee']) / 100, 2, ',', "\u{202F}") }} €</td>
                        <td>{{ $importable ? '—' : 'Déjà saisie' }}</td>
                    </tr>
                @endforeach
            </tbody>
            {{--
                Le total coché n'est pas décoratif : c'est le montant qui partira en
                recettes, et c'est ce que l'utilisateur doit pouvoir rapprocher du
                « Total » imprimé sur son relevé avant de cliquer.
            --}}
            <tfoot>
                @php($retenues = collect($rows)->where('selected', true)->filter(fn ($r) => empty($r['duplicate'])))
                <tr>
                    <td></td>
                    <td><strong>Total coché</strong></td>
                    <td class="num"><strong>{{ number_format($retenues->sum('amount') / 100, 2, ',', "\u{202F}") }} €</strong></td>
                    <td class="num"><strong>{{ number_format($retenues->sum('platform_fee') / 100, 2, ',', "\u{202F}") }} €</strong></td>
                    <td class="num"><strong>{{ number_format($retenues->sum(fn ($r) => $r['amount'] - $r['platform_fee']) / 100, 2, ',', "\u{202F}") }} €</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
@endif
