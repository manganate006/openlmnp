<x-filament-panels::page>
    {{-- Aucun utilitaire Tailwind dans le CSS du panel (cf. app/CLAUDE.md) : classes scopées `dp-*`. --}}
    <style>
        .dp-intro { font-size: 13px; color: var(--olmnp-fg-muted); margin-bottom: 16px; max-width: 900px; }
        .dp-card { background: var(--olmnp-surface); border: 1px solid var(--olmnp-border); border-radius: 12px; margin-bottom: 12px; }
        .dp-card > summary { cursor: pointer; padding: 14px 18px; list-style: none; }
        .dp-card > summary::-webkit-details-marker { display: none; }
        .dp-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; }
        .dp-name { font-weight: 600; font-size: 14px; color: var(--olmnp-fg-strong); }
        .dp-origin { font-size: 12px; color: var(--olmnp-fg-muted); margin-left: 6px; }
        .dp-facts { display: flex; gap: 18px; flex-wrap: wrap; font-size: 12px; color: var(--olmnp-fg-muted); margin-top: 6px; }
        .dp-facts strong { color: var(--olmnp-fg); font-weight: 600; }
        .dp-body { padding: 0 18px 16px; overflow-x: auto; }
        .dp-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .dp-table th, .dp-table td { padding: 6px 8px; border-bottom: 1px solid var(--olmnp-border); }
        .dp-table th { background: var(--olmnp-surface-muted); font-weight: 600; font-size: 11px; text-align: left; }
        .dp-table .dp-r { text-align: right; font-family: monospace; white-space: nowrap; }
        .dp-table .dp-current { background: var(--olmnp-success-bg); font-weight: 600; }
        .dp-note { font-size: 11px; color: var(--olmnp-fg-muted); }
        .dp-empty { background: var(--olmnp-surface); border: 1px solid var(--olmnp-border); border-radius: 12px; padding: 32px; text-align: center; color: var(--olmnp-fg-muted); }
    </style>

    @php
        $data = $this->planData;
        $fmt = fn ($cents) => number_format($cents / 100, 2, ',', ' ') . ' €';
        $origins = ['building' => 'Composant du bien', 'work' => 'Travaux', 'furniture' => 'Mobilier', 'notary' => 'Frais d\'acquisition'];
    @endphp

    @if($data === null)
        @if($propertyId === null)
            <div class="dp-empty">Choisissez un bien ci-dessus pour afficher son plan d'amortissement.</div>
        @endif
    @elseif($data['plans'] === [])
        <div class="dp-empty">Ce bien n'a encore rien à amortir : ni composant, ni travaux, ni mobilier.</div>
    @else
        <p class="dp-intro">
            Chaque élément s'amortit de sa mise en service à la fin de sa durée. La première année,
            l'annuité est réduite au prorata des jours restants ; si elle ne commence pas un
            1er janvier, le plan dure un exercice de plus, qui reprend la part retirée la première
            année. Les montants sont ceux de la liasse : l'annexe « Détail des immobilisations »
            reprend, pour un exercice, la ligne de l'année de ce tableau.
        </p>

        @foreach($data['plans'] as $plan)
            @php
                // Cumul et reste à la fin de l'année en cours (ou du dernier exercice du plan).
                $now = collect($plan['years'])->last(fn ($y) => $y['year'] <= $data['year']);
                $prorata = $plan['prorata'];
            @endphp
            <details class="dp-card">
                <summary>
                    <div class="dp-head">
                        <div>
                            <span class="dp-name">{{ $plan['name'] }}</span>
                            <span class="dp-origin">{{ $origins[$plan['type']] ?? $plan['type'] }}</span>
                        </div>
                        <div class="dp-facts">
                            <span>Reste à amortir fin {{ $now['year'] ?? $data['year'] }} : <strong>{{ $fmt($now['remaining'] ?? $plan['base']) }}</strong></span>
                        </div>
                    </div>
                    <div class="dp-facts">
                        <span>Valeur brute <strong>{{ $fmt($plan['base']) }}</strong></span>
                        <span>Mise en service <strong>{{ \Carbon\Carbon::parse($plan['in_service'])->format('d/m/Y') }}</strong></span>
                        <span>Durée <strong>{{ $plan['duration'] }} ans</strong></span>
                        <span>Annuité pleine <strong>{{ $fmt($plan['full_annual']) }}</strong></span>
                        <span>Prorata 1re année <strong>{{ $prorata['days'] === $prorata['of'] ? 'année pleine' : $prorata['days'].'/'.$prorata['of'].' jours' }}</strong></span>
                        <span>Fin du plan <strong>{{ $plan['last_year'] }}</strong></span>
                    </div>
                </summary>
                <div class="dp-body">
                    <table class="dp-table">
                        <thead>
                            <tr>
                                <th>Exercice</th>
                                <th class="dp-r">Dotation</th>
                                <th>Déduction</th>
                                <th class="dp-r">Amortissement cumulé</th>
                                <th class="dp-r">Reste à amortir</th>
                            </tr>
                        </thead>
                        <tbody>
                            @if($plan['opening'] > 0)
                                <tr>
                                    <td>{{ $plan['opening_year'] ? 'Jusqu\'à '.$plan['opening_year'] : 'Repris' }}</td>
                                    <td class="dp-r">—</td>
                                    <td class="dp-note">cumul repris de votre comptabilité antérieure</td>
                                    <td class="dp-r">{{ $fmt($plan['opening']) }}</td>
                                    <td class="dp-r">{{ $fmt($plan['base'] - $plan['opening']) }}</td>
                                </tr>
                            @endif
                            @foreach($plan['years'] as $row)
                                <tr class="{{ $row['year'] === $data['year'] ? 'dp-current' : '' }}">
                                    <td>{{ $row['year'] }}</td>
                                    <td class="dp-r">{{ $fmt($row['annual']) }}</td>
                                    <td class="dp-note">
                                        @if($row['annual'] === 0)
                                            —
                                        @elseif($row['deducted'] === 0)
                                            réputée pratiquée (micro-BIC), non déduite
                                        @else
                                            déductible
                                        @endif
                                    </td>
                                    <td class="dp-r">{{ $fmt($row['cumul']) }}</td>
                                    <td class="dp-r">{{ $fmt($row['remaining']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="dp-note" style="margin-top:8px;">
                        « Déductible » ne veut pas dire « déduite en totalité » : quand l'amortissement
                        dépasse le résultat, l'excédent est différé (art. 39 C du CGI) et reporté sur les
                        exercices suivants. Ce report se lit dans la liasse, pour l'ensemble du bien.
                    </p>
                </div>
            </details>
        @endforeach
    @endif
</x-filament-panels::page>
