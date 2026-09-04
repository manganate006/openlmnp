{{--
    Assistant « Reprendre un dossier existant » — rendu des 9 écrans de
    `_admin/docs/maquettes-reprise/wizard-reprise.html`.

    AUCUNE couleur en dur : tout passe par les jetons `--olmnp-*` de
    `filament/partials/theme-tokens.blade.php` (`PanelStylesheetTest` échoue sinon), et
    les deux thèmes sont donc couverts par construction.

    L'étape 3 inclut `filament/partials/depreciation-editor-core.blade.php` : c'est
    l'éditeur d'amortissements existant, avec ses deux modes, et non un troisième éditeur.
--}}
<x-filament-panels::page>
    {{-- Style + composant Alpine de l'éditeur : présents dès le premier rendu, sinon
         l'étape 3 apparaîtrait par un morphing Livewire, qui n'exécute pas les <script>. --}}
    @include('filament.partials.depreciation-editor-assets')

    <style>
        .rp-wizard { --rp-shadow: rgba(0, 0, 0, .10); }

        /* ---------- Cadre ---------- */
        .rp-screen {
            background: var(--olmnp-surface);
            border: 1px solid var(--olmnp-border);
            border-radius: 14px;
            box-shadow: 0 1px 2px var(--rp-shadow);
            overflow: hidden;
        }
        .rp-screen-head { padding: 16px 20px 0; }
        .rp-screen-head h2 { margin: 0 0 2px; font-size: 17px; font-weight: 600; color: var(--olmnp-fg-strong); }
        .rp-screen-head p { margin: 0; font-size: 13px; color: var(--olmnp-fg-muted); }
        .rp-body { padding: 18px 20px 20px; font-size: 14px; line-height: 1.5; color: var(--olmnp-fg); }

        /* ---------- Fil des étapes ---------- */
        .rp-steps { display: flex; gap: 4px; margin: 16px 0 0; padding: 0 20px 14px; border-bottom: 1px solid var(--olmnp-border); overflow-x: auto; }
        .rp-step { display: flex; align-items: center; gap: 7px; padding: 6px 10px; border-radius: 9px; white-space: nowrap; font-size: 12.5px; color: var(--olmnp-fg-muted); background: none; border: none; font-family: inherit; }
        .rp-step-done { cursor: pointer; }
        .rp-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 20px; height: 20px; border-radius: 999px; font-size: 11.5px; font-weight: 600;
            background: var(--olmnp-surface-alt); color: var(--olmnp-fg-muted);
            border: 1px solid var(--olmnp-border-strong);
        }
        .rp-step-done { color: var(--olmnp-fg); }
        .rp-step-done .rp-num { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); border-color: var(--olmnp-success-border); }
        .rp-step-active { background: var(--olmnp-success-bg); color: var(--olmnp-success-fg); font-weight: 600; }
        .rp-step-active .rp-num { background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); border-color: var(--olmnp-success-solid); }

        /* ---------- Champs ---------- */
        .rp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); gap: 14px; }
        .rp-field label { display: block; font-size: 12.5px; font-weight: 600; color: var(--olmnp-fg-strong); margin-bottom: 5px; }
        .rp-hint { display: block; margin-top: 5px; font-size: 11.5px; color: var(--olmnp-fg-muted); }
        .rp-cerfa { font-weight: 600; color: var(--olmnp-info-accent); }
        .rp-error { display: block; margin-top: 5px; font-size: 11.5px; font-weight: 600; color: var(--olmnp-danger-accent); }
        .rp-input {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            padding: 0 11px; border-radius: 9px; font-size: 13.5px;
            background: var(--olmnp-surface); color: var(--olmnp-fg-strong);
            border: 1px solid var(--olmnp-border-strong);
        }
        .rp-input input, .rp-input select {
            flex: 1 1 auto; min-width: 0; padding: 8px 0; font: inherit; font-size: 13.5px;
            background: transparent; color: var(--olmnp-fg-strong); border: none; outline: none;
        }
        .rp-input select option { background: var(--olmnp-surface); color: var(--olmnp-fg-strong); }
        .rp-input i { font-style: normal; color: var(--olmnp-fg-subtle); font-size: 12px; }
        .rp-input-bad { border-color: var(--olmnp-danger-border); }
        .rp-readonly { color: var(--olmnp-fg-muted); }

        /* ---------- Choix ---------- */
        .rp-choices { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr)); gap: 12px; }
        .rp-choice {
            display: block; width: 100%; text-align: left; font-family: inherit;
            padding: 13px 14px; border-radius: 12px; cursor: pointer;
            background: var(--olmnp-surface); border: 1px solid var(--olmnp-border-strong);
        }
        .rp-choice b { display: block; font-size: 13.5px; color: var(--olmnp-fg-strong); margin-bottom: 3px; }
        .rp-choice span { font-size: 12.5px; color: var(--olmnp-fg-muted); }
        .rp-choice-on { border-color: var(--olmnp-success-solid); background: var(--olmnp-success-bg); }
        .rp-choice-on b { color: var(--olmnp-success-fg); }
        .rp-choice-on span { color: var(--olmnp-success-fg); }
        .rp-choice-meta {
            display: block; width: fit-content; margin-top: 9px; padding: 2px 9px; border-radius: 999px;
            font-size: 11px; font-weight: 600;
            background: var(--olmnp-surface-alt); color: var(--olmnp-fg-muted);
        }
        .rp-choice-on .rp-choice-meta { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .rp-pros { list-style: none; margin: 11px 0 0; padding: 0; font-size: 12px; }
        .rp-pros li { position: relative; padding: 2.5px 0 2.5px 19px; color: var(--olmnp-fg); }
        .rp-pros li::before { position: absolute; left: 1px; top: 2.5px; font-weight: 700; line-height: 1.45; }
        .rp-pros li.pro::before { content: "\2713"; color: var(--olmnp-success-accent); }
        .rp-pros li.con::before { content: "\2717"; color: var(--olmnp-danger-accent); }

        /* ---------- Encarts ---------- */
        .rp-note { margin-top: 16px; padding: 12px 14px; border-radius: 11px; font-size: 12.5px; }
        .rp-note b { display: block; margin-bottom: 3px; font-size: 13px; }
        .rp-note-info { background: var(--olmnp-info-bg); border: 1px solid var(--olmnp-info-border); color: var(--olmnp-info-fg); }
        .rp-note-warn { background: var(--olmnp-warning-bg); border: 1px solid var(--olmnp-warning-border); color: var(--olmnp-warning-fg); }
        .rp-note-ok { background: var(--olmnp-success-bg); border: 1px solid var(--olmnp-success-border); color: var(--olmnp-success-fg); }
        .rp-note-bad { background: var(--olmnp-danger-bg); border: 1px solid var(--olmnp-danger-border); color: var(--olmnp-danger-fg); }
        .rp-note ul { margin: 8px 0 0; padding-left: 18px; }
        .rp-note li { margin-bottom: 4px; }
        .rp-note-lead { font-weight: 600; }

        /* ---------- Tableaux ---------- */
        .rp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .rp-table th {
            text-align: left; font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em;
            color: var(--olmnp-fg-muted); padding: 8px 10px; border-bottom: 1px solid var(--olmnp-border);
        }
        .rp-table td { padding: 9px 10px; border-bottom: 1px solid var(--olmnp-border); color: var(--olmnp-fg); }
        .rp-table td.rp-num-cell, .rp-table th.rp-num-cell { text-align: right; font-variant-numeric: tabular-nums; }
        .rp-table tbody tr:last-child td { border-bottom: none; }
        .rp-table i { font-style: normal; color: var(--olmnp-fg-muted); font-size: 11.5px; }
        .rp-pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .rp-pill-ok { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .rp-pill-warn { background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); }
        .rp-pill-bad { background: var(--olmnp-danger-bg-strong); color: var(--olmnp-danger-fg); }
        .rp-pill-muted { background: var(--olmnp-surface-alt); color: var(--olmnp-fg-muted); }
        .rp-pill-custom { background: var(--olmnp-accent-bg); color: var(--olmnp-accent-fg); border: 1px solid var(--olmnp-accent-border); }

        /* ---------- Boutons ---------- */
        .rp-actions { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--olmnp-border); }
        .rp-actions-left { display: flex; gap: 8px; flex-wrap: wrap; }
        .rp-btn { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 9px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid transparent; font-family: inherit; }
        .rp-btn-primary { background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); }
        .rp-btn-primary:hover { background: var(--olmnp-success-solid-hover); }
        .rp-btn-ghost { background: var(--olmnp-surface); color: var(--olmnp-fg); border-color: var(--olmnp-border-strong); }
        .rp-btn-ghost:hover { background: var(--olmnp-surface-alt); }
        .rp-btn-tiny { padding: 3px 9px; font-size: 11px; border-radius: 999px; }
        .rp-inline-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }

        /* ---------- Récapitulatif final ---------- */
        .rp-recap { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: 12px; }
        .rp-recap-card { padding: 13px 14px; border-radius: 12px; background: var(--olmnp-surface-muted); border: 1px solid var(--olmnp-border); }
        .rp-recap-card b { display: block; font-size: 19px; color: var(--olmnp-fg-strong); font-variant-numeric: tabular-nums; }
        .rp-recap-card span { font-size: 12px; color: var(--olmnp-fg-muted); }
    </style>

    <div class="rp-wizard">
        @if($this->finished)
            {{-- ============ Fin — ce qui a été repris, et ce qui ne l'a pas été ============ --}}
            @php $recap = $this->recap(); @endphp
            <div class="rp-screen">
                <div class="rp-body">
                    <div class="rp-recap">
                        <div class="rp-recap-card">
                            <b>{{ $this->formatEuros($recap['annual_depreciation']) }}</b>
                            <span>Dotation d'amortissement {{ $this->firstYear }}</span>
                        </div>
                        <div class="rp-recap-card">
                            <b>{{ $this->formatEuros($recap['deferred']) }}</b>
                            <span>Amortissements différés reportés</span>
                        </div>
                        <div class="rp-recap-card">
                            <b>{{ $this->formatEuros($recap['deficits']) }}</b>
                            <span>
                                @if($recap['deficit_expiry'])
                                    Déficit imputable jusqu'en {{ $recap['deficit_expiry'] }}
                                @else
                                    Aucun déficit reportable
                                @endif
                            </span>
                        </div>
                        <div class="rp-recap-card">
                            <b>{{ $this->formatEuros($recap['accumulated']) }}</b>
                            <span>Cumul d'amortissements repris</span>
                        </div>
                    </div>

                    <div class="rp-note rp-note-info">
                        <b>Ce qui n'a pas été repris, volontairement.</b>
                        <ul>
                            <li>Vos <strong>recettes et charges des années passées</strong> : elles ne servent plus à rien, vos reports sont saisis. Si vous les voulez quand même pour l'historique, l'import CSV les accepte.</li>
                            <li>Vos <strong>justificatifs antérieurs</strong> : à déposer au fil de l'eau, ou en lot depuis la page Documents.</li>
                            <li>Vos <strong>liasses passées</strong> : elles restent celles de votre comptable, OpenLMNP ne les réécrit pas.</li>
                        </ul>
                    </div>

                    <div class="rp-actions">
                        <button type="button" class="rp-btn rp-btn-ghost" wire:click="reviewReprise">Revoir ma reprise</button>
                        <a class="rp-btn rp-btn-primary" href="{{ \App\Filament\Pages\FiscalYearWizard::getUrl() }}">
                            Créer mon exercice {{ $this->firstYear }}
                        </a>
                    </div>
                </div>
            </div>
        @else
            <div class="rp-screen">
                {{-- ============ Fil des étapes ============ --}}
                <nav class="rp-steps">
                    @foreach($this->steps() as $index => $stepDefinition)
                        @php $number = $index + 1; @endphp
                        <button
                            type="button"
                            @class([
                                'rp-step',
                                'rp-step-done' => $number < $this->step,
                                'rp-step-active' => $number === $this->step,
                            ])
                            @if($number < $this->step) wire:click="goToStep({{ $number }})" @endif
                        >
                            <span class="rp-num">{{ $number < $this->step ? '✓' : $number }}</span>{{ $stepDefinition['title'] }}
                        </button>
                    @endforeach
                </nav>

                <div class="rp-body">
                    {{-- ==================== Étape 1 — votre situation ==================== --}}
                    @if($this->step === 1)
                        <div class="rp-grid">
                            <div class="rp-field">
                                <label for="rp-rental-start">Depuis quand louez-vous ce bien ?</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['rentalStartDate'])])>
                                    <input id="rp-rental-start" type="text" inputmode="numeric" placeholder="jj/mm/aaaa" wire:model="rentalStartDate">
                                    <i>jj/mm/aaaa</i>
                                </div>
                                <span class="rp-hint">C'est la date qui fait démarrer vos amortissements.</span>
                                @isset($this->stepErrors['rentalStartDate'])
                                    <span class="rp-error">{{ $this->stepErrors['rentalStartDate'] }}</span>
                                @endisset
                            </div>
                            <div class="rp-field">
                                <label for="rp-first-year">Première année à tenir dans OpenLMNP</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['firstYear'])])>
                                    <select id="rp-first-year" wire:model="firstYear">
                                        @foreach($this->yearOptions() as $year)
                                            <option value="{{ $year }}">{{ $year }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <span class="rp-hint">
                                    Votre comptable a établi la liasse jusqu'à {{ $this->referenceYear() }} :
                                    nous reprenons à {{ $this->firstYear }}.
                                </span>
                                @isset($this->stepErrors['firstYear'])
                                    <span class="rp-error">{{ $this->stepErrors['firstYear'] }}</span>
                                @endisset
                            </div>
                        </div>

                        <div class="rp-field" style="margin-top:14px">
                            <label>Avez-vous déjà déclaré au régime réel ?</label>
                            <div class="rp-choices">
                                @foreach([
                                    \App\Filament\Pages\RepriseDossier::REGIME_SINCE_START => 'Mes amortissements courent depuis la mise en location.',
                                    \App\Filament\Pages\RepriseDossier::REGIME_SINCE_YEAR => 'J\'étais au micro-BIC avant.',
                                    \App\Filament\Pages\RepriseDossier::REGIME_NEW => 'Aucun amortissement pratiqué jusqu\'ici.',
                                ] as $value => $description)
                                    <button
                                        type="button"
                                        @class(['rp-choice', 'rp-choice-on' => $this->regime === $value])
                                        wire:click="$set('regime', '{{ $value }}')"
                                    >
                                        <b>{{ \App\Filament\Pages\RepriseDossier::regimeLabels()[$value] }}</b>
                                        <span>{{ $description }}</span>
                                    </button>
                                @endforeach
                            </div>
                            @if($this->regime === \App\Filament\Pages\RepriseDossier::REGIME_SINCE_YEAR)
                                <div class="rp-field" style="margin-top:12px;max-width:260px">
                                    <label for="rp-regime-year">Depuis quelle année déclarez-vous au réel ?</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['regimeSinceYear'])])>
                                        <input id="rp-regime-year" type="number" wire:model="regimeSinceYear" min="2000" max="{{ date('Y') }}">
                                    </div>
                                    @isset($this->stepErrors['regimeSinceYear'])
                                        <span class="rp-error">{{ $this->stepErrors['regimeSinceYear'] }}</span>
                                    @endisset
                                </div>
                            @endif
                        </div>

                        @if($this->regime === \App\Filament\Pages\RepriseDossier::REGIME_NEW)
                            <div class="rp-note rp-note-warn">
                                <b>Vous n'avez rien à reprendre.</b>
                                Sans amortissement pratiqué avant cette année, il n'y a ni cumul, ni report à saisir.
                                Vous pouvez continuer — l'étape 3 vous proposera la ventilation automatique, qui est
                                le bon choix dans ce cas — ou passer directement par « Premier lancement ».
                            </div>
                        @else
                            <div class="rp-note rp-note-info">
                                <b>Vous n'aurez pas à ressaisir vos exercices passés.</b>
                                Trois chiffres lus sur votre liasse {{ $this->referenceYear() }} suffisent. À la fin,
                                nous vérifierons avec vous que nos calculs tombent exactement sur les siens.
                            </div>
                        @endif

                        <div class="rp-actions">
                            <span></span>
                            <button type="button" class="rp-btn rp-btn-primary" wire:click="nextStep">Continuer</button>
                        </div>
                    @endif

                    {{-- ==================== Étape 2 — votre bien ==================== --}}
                    @if($this->step === 2)
                        @if($this->propertyId === null)
                            <div class="rp-note rp-note-info" style="margin-top:0">
                                <b>Ce bien n'existe pas encore dans OpenLMNP.</b>
                                Nous avons besoin de son identité pour le créer. Vous pourrez tout modifier ensuite
                                depuis « Mes biens ».
                            </div>
                            <div class="rp-grid" style="margin-top:16px">
                                <div class="rp-field">
                                    <label for="rp-name">Nom du bien</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['propertyName'])])>
                                        <input id="rp-name" type="text" wire:model="propertyName" placeholder="Studio Bordeaux">
                                    </div>
                                    @isset($this->stepErrors['propertyName'])
                                        <span class="rp-error">{{ $this->stepErrors['propertyName'] }}</span>
                                    @endisset
                                </div>
                                <div class="rp-field">
                                    <label for="rp-address">Adresse</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['propertyAddress'])])>
                                        <input id="rp-address" type="text" wire:model="propertyAddress">
                                    </div>
                                    @isset($this->stepErrors['propertyAddress'])
                                        <span class="rp-error">{{ $this->stepErrors['propertyAddress'] }}</span>
                                    @endisset
                                </div>
                                <div class="rp-field">
                                    <label for="rp-city">Ville</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['propertyCity'])])>
                                        <input id="rp-city" type="text" wire:model="propertyCity">
                                    </div>
                                    @isset($this->stepErrors['propertyCity'])
                                        <span class="rp-error">{{ $this->stepErrors['propertyCity'] }}</span>
                                    @endisset
                                </div>
                                <div class="rp-field">
                                    <label for="rp-postal">Code postal</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['propertyPostalCode'])])>
                                        <input id="rp-postal" type="text" wire:model="propertyPostalCode">
                                    </div>
                                    @isset($this->stepErrors['propertyPostalCode'])
                                        <span class="rp-error">{{ $this->stepErrors['propertyPostalCode'] }}</span>
                                    @endisset
                                </div>
                                <div class="rp-field">
                                    <label for="rp-area">Surface louée</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['propertyArea'])])>
                                        <input id="rp-area" type="number" wire:model="propertyArea" min="1">
                                        <i>m²</i>
                                    </div>
                                    <span class="rp-hint">Bien loué en entier. Si vous ne louez qu'une partie, ajustez la quote-part dans « Mes biens ».</span>
                                    @isset($this->stepErrors['propertyArea'])
                                        <span class="rp-error">{{ $this->stepErrors['propertyArea'] }}</span>
                                    @endisset
                                </div>
                                <div class="rp-field">
                                    <label for="rp-acquisition-date">Date d'acquisition</label>
                                    <div class="rp-input">
                                        <input id="rp-acquisition-date" type="text" inputmode="numeric" placeholder="jj/mm/aaaa" wire:model="acquisitionDate">
                                        <i>jj/mm/aaaa</i>
                                    </div>
                                    <span class="rp-hint">Laissée vide, la date de mise en location est retenue.</span>
                                </div>
                            </div>
                        @endif

                        <div class="rp-grid" @if($this->propertyId === null) style="margin-top:14px" @endif>
                            <div class="rp-field">
                                <label for="rp-price">Prix d'acquisition</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['acquisitionPrice'])])>
                                    <input id="rp-price" type="text" inputmode="decimal" wire:model.live.debounce.600ms="acquisitionPrice">
                                    <i>€</i>
                                </div>
                                <span class="rp-hint">
                                    Le prix payé, hors frais. <span class="rp-cerfa">2033-A case 028</span> si vous le lisez sur votre liasse.
                                </span>
                                @isset($this->stepErrors['acquisitionPrice'])
                                    <span class="rp-error">{{ $this->stepErrors['acquisitionPrice'] }}</span>
                                @endisset
                            </div>
                            <div class="rp-field">
                                <label for="rp-notary">Frais de notaire</label>
                                <div class="rp-input">
                                    <input id="rp-notary" type="text" inputmode="decimal" wire:model="notaryFees">
                                    <i>€</i>
                                </div>
                            </div>
                            <div class="rp-field">
                                <label for="rp-agency">Honoraires d'agence</label>
                                <div class="rp-input">
                                    <input id="rp-agency" type="text" inputmode="decimal" wire:model="agencyFees">
                                    <i>€</i>
                                </div>
                            </div>
                            <div class="rp-field">
                                <label for="rp-land">Part du terrain</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['landPercentage'])])>
                                    <input id="rp-land" type="number" wire:model.live="landPercentage" min="0" max="99">
                                    <i>%</i>
                                </div>
                                <span class="rp-hint">Non amortissable. Reprenez la valeur retenue par votre comptable.</span>
                                @isset($this->stepErrors['landPercentage'])
                                    <span class="rp-error">{{ $this->stepErrors['landPercentage'] }}</span>
                                @endisset
                            </div>
                            <div class="rp-field">
                                <label for="rp-fees-treatment">Traitement des frais d'acquisition</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['acquisitionFeesTreatment'])])>
                                    <select id="rp-fees-treatment" wire:model.live="acquisitionFeesTreatment">
                                        @foreach(\App\Models\Property::acquisitionFeesTreatmentLabels() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <span class="rp-hint">Votre comptable les a peut-être passés en charges l'année de l'achat : dans ce cas, choisissez « passés en charges ».</span>
                                @isset($this->stepErrors['acquisitionFeesTreatment'])
                                    <span class="rp-error">{{ $this->stepErrors['acquisitionFeesTreatment'] }}</span>
                                @endisset
                            </div>
                            @if($this->acquisitionFeesTreatment === \App\Models\Property::ACQUISITION_FEES_AMORTIZED)
                                <div class="rp-field">
                                    <label for="rp-fees-duration">Durée d'amortissement des frais</label>
                                    <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['acquisitionFeesDuration'])])>
                                        <input id="rp-fees-duration" type="number" wire:model="acquisitionFeesDuration" min="1" max="50">
                                        <i>ans</i>
                                    </div>
                                    @isset($this->stepErrors['acquisitionFeesDuration'])
                                        <span class="rp-error">{{ $this->stepErrors['acquisitionFeesDuration'] }}</span>
                                    @endisset
                                </div>
                            @endif
                            <div class="rp-field">
                                <label>Base amortissable calculée</label>
                                <div class="rp-input rp-readonly">
                                    <span>{{ $this->formatEuros($this->depreciableBaseCents()) }}</span>
                                </div>
                                <span class="rp-hint">
                                    (prix d'acquisition − {{ (int) $this->landPercentage }} % de terrain). C'est elle que vous allez ventiler.
                                </span>
                            </div>
                        </div>

                        <div class="rp-actions">
                            <button type="button" class="rp-btn rp-btn-ghost" wire:click="previousStep">Retour</button>
                            <button type="button" class="rp-btn rp-btn-primary" wire:click="nextStep">Continuer</button>
                        </div>
                    @endif

                    {{-- ==================== Étape 3 — méthode, puis la grille ==================== --}}
                    @if($this->step === 3)
                        @if($this->method === null)
                            <div class="rp-field">
                                <label>Comment voulez-vous reprendre votre plan d'amortissement ?</label>
                                <div class="rp-choices">
                                    <button type="button" class="rp-choice" wire:click="chooseMethod('{{ \App\Filament\Pages\RepriseDossier::METHOD_COPY }}')">
                                        <b>Recopier les lignes de ma liasse</b>
                                        <span>Vous saisissez la base et la durée de chaque composant, lues sur votre tableau <span class="rp-cerfa">2033-C</span>.</span>
                                        <span class="rp-choice-meta">≈ 10 minutes, liasse sous les yeux</span>
                                        <ul class="rp-pros">
                                            <li class="pro">Vos chiffres restent <strong>exactement ceux de votre comptable</strong> : le contrôle de l'étape 5 tombe juste.</li>
                                            <li class="pro">Rien ne sort de chez vous, aucun service extérieur.</li>
                                            <li class="pro">Vous gardez la main sur chaque durée, y compris les composants inhabituels.</li>
                                            <li class="con">Une dizaine de champs à saisir, votre liasse ouverte à côté.</li>
                                            <li class="con">Une faute de frappe ne se voit qu'au contrôle de l'étape 5.</li>
                                        </ul>
                                    </button>
                                    <button type="button" class="rp-choice" wire:click="chooseMethod('{{ \App\Filament\Pages\RepriseDossier::METHOD_SPREAD }}')">
                                        <b>Répartir automatiquement ma base</b>
                                        <span>Nous appliquons la ventilation habituelle (50 % gros œuvre, 10 % toiture…) sur votre base amortissable.</span>
                                        <span class="rp-choice-meta">Un clic, rien à saisir</span>
                                        <ul class="rp-pros">
                                            <li class="pro">Aucun chiffre à recopier : la ventilation et les durées sont proposées.</li>
                                            <li class="pro">Durées conformes aux usages admis en location meublée.</li>
                                            <li class="pro">Le bon choix si vous sortez du micro-BIC : il n'y a rien à reprendre.</li>
                                            <li class="con"><strong>Ne reproduit pas le plan de votre comptable</strong> : dotations et cumul seront différents des siens.</li>
                                            <li class="con">Si vous avez déjà amorti au réel, l'étape 5 signalera l'écart — et il faudra revenir ici.</li>
                                        </ul>
                                    </button>
                                </div>
                                @isset($this->stepErrors['method'])
                                    <span class="rp-error">{{ $this->stepErrors['method'] }}</span>
                                @endisset
                            </div>

                            <div class="rp-note rp-note-warn">
                                <b>Si vous avez un plan, recopiez-le.</b>
                                La ventilation automatique donnera un plan valable, mais différent de celui de votre
                                comptable : votre cumul d'amortissements ne tombera pas sur le sien, et l'étape 5 vous
                                le signalera.
                            </div>

                            <div class="rp-actions">
                                <button type="button" class="rp-btn rp-btn-ghost" wire:click="previousStep">Retour</button>
                                <button type="button" class="rp-btn rp-btn-primary" wire:click="nextStep">Continuer</button>
                            </div>
                        @else
                            <div class="rp-inline-actions">
                                <button type="button" class="rp-btn rp-btn-ghost" wire:click="$set('method', null)">
                                    Changer de méthode
                                </button>
                                <span class="rp-pill rp-pill-custom" style="align-self:center">
                                    @if($this->method === \App\Filament\Pages\RepriseDossier::METHOD_COPY)
                                        Recopie de la liasse (tableau 2033-C)
                                    @else
                                        Ventilation automatique
                                    @endif
                                </span>
                            </div>

                            {{-- ⚠️ `wire:key` OBLIGATOIRE. L'éditeur porte un `wire:ignore` : Livewire
                                 ne touche pas à son contenu, et lors d'un morphing il CONSERVE le
                                 nœud au lieu de le retirer. Sans clé variable, cliquer sur
                                 « Changer de méthode » affichait les deux cartes de choix ET
                                 l'ancien éditeur, l'un sous l'autre. --}}
                            <div wire:key="reprise-editor-{{ $this->method }}-{{ $this->propertyId }}">
                                @include('filament.partials.depreciation-editor-core', [
                                    'data' => array_merge($this->editorData, ['initialMode' => $this->editorInitialMode()]),
                                    'properties' => [],
                                    'propertyId' => $this->propertyId,
                                    'showReset' => false,
                                    'saveLabel' => 'Enregistrer mon plan',
                                ])
                            </div>

                            <div class="rp-note rp-note-info">
                                <b>Enregistrez votre plan avant de continuer.</b>
                                Les colonnes « Départ » et « Cumul au 31/12/{{ $this->referenceYear() }} » sont celles
                                qui permettront au contrôle de l'étape 5 de retomber sur les chiffres de votre comptable.
                            </div>

                            <div class="rp-actions">
                                <button type="button" class="rp-btn rp-btn-ghost" wire:click="previousStep">Retour</button>
                                <button type="button" class="rp-btn rp-btn-primary" wire:click="nextStep">Continuer</button>
                            </div>
                        @endif
                    @endif

                    {{-- ==================== Étape 4 — vos reports ==================== --}}
                    @if($this->step === 4)
                        <div class="rp-note rp-note-info" style="margin-top:0">
                            <b>Différé n'est pas déficit.</b>
                            L'<strong>amortissement différé</strong> est la part d'amortissement que vous n'avez pas pu
                            déduire parce qu'elle aurait créé un déficit : elle se reporte sans limite de durée. Le
                            <strong>déficit</strong>, lui, vient de vos charges, et ne s'impute que sur vos bénéfices de
                            location meublée des dix années suivantes.
                        </div>

                        <div class="rp-grid" style="margin-top:16px">
                            <div class="rp-field">
                                <label for="rp-deferred">Amortissements différés au 31/12/{{ $this->referenceYear() }}</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['openingDeferred'])])>
                                    <input id="rp-deferred" type="text" inputmode="decimal" wire:model="openingDeferred">
                                    <i>€</i>
                                </div>
                                <span class="rp-hint">
                                    Liasse <span class="rp-cerfa">2033-D, case 870</span>. Si votre comptable ne remplit
                                    pas le 2033-D : <span class="rp-cerfa">2033-B, case 318</span> cumulée.
                                </span>
                                @isset($this->stepErrors['openingDeferred'])
                                    <span class="rp-error">{{ $this->stepErrors['openingDeferred'] }}</span>
                                @endisset
                            </div>
                            <div class="rp-field">
                                <label for="rp-accumulated">Cumul d'amortissements pratiqués</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['openingAccumulated'])])>
                                    <input id="rp-accumulated" type="text" inputmode="decimal" wire:model="openingAccumulated">
                                    <i>€</i>
                                </div>
                                <span class="rp-hint">
                                    Liasse <span class="rp-cerfa">2033-A, case 030</span>. Sert uniquement au contrôle
                                    de l'étape suivante.
                                </span>
                                @isset($this->stepErrors['openingAccumulated'])
                                    <span class="rp-error">{{ $this->stepErrors['openingAccumulated'] }}</span>
                                @endisset
                            </div>
                            <div class="rp-field">
                                <label for="rp-gross">Immobilisations brutes</label>
                                <div @class(['rp-input', 'rp-input-bad' => isset($this->stepErrors['declaredGrossAssets'])])>
                                    <input id="rp-gross" type="text" inputmode="decimal" wire:model="declaredGrossAssets">
                                    <i>€</i>
                                </div>
                                <span class="rp-hint">
                                    Liasse <span class="rp-cerfa">2033-A, case 028</span>. Facultatif : laissé vide,
                                    cette ligne n'est pas comparée à l'étape 5.
                                </span>
                                @isset($this->stepErrors['declaredGrossAssets'])
                                    <span class="rp-error">{{ $this->stepErrors['declaredGrossAssets'] }}</span>
                                @endisset
                            </div>
                        </div>

                        <div class="rp-field" style="margin-top:16px">
                            <label>Déficits reportables, par année d'origine</label>
                            @if($this->deficits !== [])
                                <table class="rp-table">
                                    <thead>
                                        <tr>
                                            <th>Année d'origine</th>
                                            <th class="rp-num-cell">Montant restant</th>
                                            <th class="rp-num-cell">Imputable jusqu'à</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($this->deficits as $index => $deficit)
                                            <tr>
                                                <td>
                                                    <div class="rp-input" style="max-width:130px">
                                                        <input type="number" wire:model.live="deficits.{{ $index }}.origin_year" min="2000" max="{{ date('Y') }}">
                                                    </div>
                                                </td>
                                                <td class="rp-num-cell">
                                                    <div class="rp-input" style="max-width:150px;margin-left:auto">
                                                        <input type="text" inputmode="decimal" wire:model="deficits.{{ $index }}.amount">
                                                        <i>€</i>
                                                    </div>
                                                </td>
                                                <td class="rp-num-cell">{{ $this->deficitExpiryYear((int) ($deficit['origin_year'] ?? 0)) }}</td>
                                                <td class="rp-num-cell">
                                                    <button type="button" class="rp-btn rp-btn-ghost rp-btn-tiny" wire:click="removeDeficit({{ $index }})">retirer</button>
                                                </td>
                                            </tr>
                                            @isset($this->stepErrors['deficits.' . $index])
                                                <tr>
                                                    <td colspan="4"><span class="rp-error">{{ $this->stepErrors['deficits.' . $index] }}</span></td>
                                                </tr>
                                            @endisset
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                            <span class="rp-hint">
                                Liasse <span class="rp-cerfa">2033-D, cases 980 à 984</span>. Laissez vide si vous n'en
                                avez pas — c'est le cas le plus fréquent.
                            </span>
                        </div>

                        <div class="rp-inline-actions" style="margin-top:12px">
                            <button type="button" class="rp-btn rp-btn-ghost" wire:click="addDeficit">+ Ajouter une année</button>
                        </div>

                        <div class="rp-actions">
                            <button type="button" class="rp-btn rp-btn-ghost" wire:click="previousStep">Retour</button>
                            <button type="button" class="rp-btn rp-btn-primary" wire:click="nextStep">Vérifier ma reprise</button>
                        </div>
                    @endif

                    {{-- ==================== Étape 5 — contrôle ==================== --}}
                    @if($this->step === 5)
                        @php $report = $this->report; @endphp
                        <table class="rp-table">
                            <thead>
                                <tr>
                                    <th>Ce que dit votre liasse {{ $this->referenceYear() }}</th>
                                    <th class="rp-num-cell">Votre liasse</th>
                                    <th class="rp-num-cell">OpenLMNP</th>
                                    <th class="rp-num-cell">Écart</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($report['lines'] ?? [] as $line)
                                    @php
                                        $transcription = in_array($line['key'], [
                                            \App\Services\ReprisesCheckService::LINE_DEFERRED_DEPRECIATION,
                                            \App\Services\ReprisesCheckService::LINE_DEFICIT_CARRYFORWARD,
                                        ], true);
                                        $pill = match ($line['verdict']) {
                                            \App\Services\ReprisesCheckService::VERDICT_MATCH => ['rp-pill-ok', $transcription ? 'repris' : 'identique'],
                                            \App\Services\ReprisesCheckService::VERDICT_CLOSE => ['rp-pill-warn', 'proche'],
                                            \App\Services\ReprisesCheckService::VERDICT_MISMATCH => ['rp-pill-bad', 'à vérifier'],
                                            default => ['rp-pill-muted', 'non renseigné'],
                                        };
                                    @endphp
                                    <tr>
                                        <td>{{ $line['label'] }} <i>· {{ $line['cerfa'] }}</i></td>
                                        <td class="rp-num-cell">{{ $this->formatEuros($line['declared']) }}</td>
                                        <td class="rp-num-cell">{{ $this->formatEuros($line['computed']) }}</td>
                                        <td class="rp-num-cell">{{ $this->formatSignedEuros($line['difference']) }}</td>
                                        <td><span class="rp-pill {{ $pill[0] }}">{{ $pill[1] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        @if($report['warning'] ?? null)
                            <div class="rp-note rp-note-warn">
                                <b>Un exercice {{ $this->referenceYear() }} vide contredit vos soldes d'ouverture.</b>
                                {{ $report['warning'] }}
                            </div>
                        @endif

                        @php
                            $verdict = $report['verdict'] ?? \App\Services\ReprisesCheckService::VERDICT_UNCHECKED;
                            $worstLine = null;
                            foreach ($report['lines'] ?? [] as $line) {
                                if ($line['verdict'] === \App\Services\ReprisesCheckService::VERDICT_MISMATCH) {
                                    $worstLine = $line;
                                    break;
                                }
                            }
                        @endphp

                        @if($verdict === \App\Services\ReprisesCheckService::VERDICT_MISMATCH && $worstLine)
                            <div class="rp-note rp-note-bad">
                                <b>
                                    Un écart de {{ $this->formatEuros(abs((int) $worstLine['difference'])) }}
                                    sur « {{ $worstLine['label'] }} ».
                                </b>
                                La cause la plus probable, puis les autres, par ordre de fréquence :
                                <ul>
                                    @foreach($worstLine['diagnostics'] as $diagnostic)
                                        <li>
                                            @if($diagnostic['corroborated'])
                                                <strong>{{ $diagnostic['label'] }}</strong>
                                            @else
                                                {{ $diagnostic['label'] }}
                                            @endif
                                            — {{ $diagnostic['hint'] }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @elseif($verdict === \App\Services\ReprisesCheckService::VERDICT_CLOSE)
                            <div class="rp-note rp-note-warn">
                                <b>Votre reprise est presque exacte.</b>
                                L'écart reste sous 1 % : il s'explique le plus souvent par une convention d'arrondi ou
                                de prorata différente de celle de votre comptable. Vous pouvez terminer la reprise.
                            </div>
                        @elseif($verdict === \App\Services\ReprisesCheckService::VERDICT_UNCHECKED)
                            <div class="rp-note rp-note-info">
                                <b>Rien à comparer.</b>
                                Vous n'avez renseigné aucun montant de votre liasse à l'étape 4. La reprise reste
                                possible, mais rien ne garantit qu'elle tombe sur les chiffres de votre comptable.
                            </div>
                        @else
                            <div class="rp-note rp-note-ok">
                                <b>Votre reprise est fidèle à votre liasse.</b>
                                OpenLMNP repart exactement des chiffres de votre comptable. Votre exercice
                                {{ $this->firstYear }} peut être créé.
                            </div>
                        @endif

                        <div class="rp-actions">
                            <div class="rp-actions-left">
                                <button type="button" class="rp-btn rp-btn-ghost" wire:click="previousStep">Retour</button>
                                @if($this->corroboratedAcquisitionFees())
                                    <button type="button" class="rp-btn rp-btn-primary" wire:click="expenseAcquisitionFees">
                                        Passer mes frais d'acquisition en charges
                                    </button>
                                @endif
                            </div>
                            @if($verdict === \App\Services\ReprisesCheckService::VERDICT_MISMATCH)
                                <button type="button" class="rp-btn rp-btn-ghost" wire:click="finish">Terminer quand même</button>
                            @else
                                <button type="button" class="rp-btn rp-btn-primary" wire:click="finish">Terminer la reprise</button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
