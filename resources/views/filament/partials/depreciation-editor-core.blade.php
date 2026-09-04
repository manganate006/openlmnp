{{--
    Éditeur de composants d'amortissement — MARKUP PARTAGÉ.

    Extrait de `filament/pages/depreciation-editor.blade.php` le 2026-09-04, pour que
    l'étape 3 de l'assistant de reprise (`/reprise`) propose EXACTEMENT les deux modes
    « Ventilation » et « Montants » qui existent déjà, au lieu d'un troisième éditeur
    qui divergerait au premier correctif.

    Variables attendues :
      $data          tableau rendu par `EditsDepreciationComponents::editorData()`
      $properties    liste [id, name] pour le sélecteur — tableau VIDE = pas de sélecteur
      $propertyId    identifiant du bien courant
      $showReset     affiche le bouton « Réinitialiser par défaut » (défaut : true)
      $saveLabel     libellé du bouton d'enregistrement (défaut : « Enregistrer »)

    ⚠️ La page hôte doit inclure `filament.partials.depreciation-editor-assets` (style +
    composant Alpine) DÈS SON PREMIER RENDU — voir l'entête de ce partial-là.

    Le composant Livewire hôte doit exposer `saveComponents()` et `resetToDefaults()` :
    c'est ce que fait le trait `App\Filament\Pages\Concerns\EditsDepreciationComponents`.
--}}
@php
    $properties = $properties ?? [];
    $showReset  = $showReset ?? true;
    $saveLabel  = $saveLabel ?? 'Enregistrer';
@endphp


    @if($data['empty'] ?? true)
        <div class="de-card" style="text-align:center;padding:48px;">
            <p style="font-size:18px;color:var(--olmnp-fg-muted);">Aucun bien enregistré. Ajoutez un bien dans Mes biens pour configurer les composants d'amortissement.</p>
        </div>
    @else
        <div
            x-data="depreciationEditor(@js($data))"
            @components-loaded.window="reload($event.detail.data)"
            wire:ignore
        >
            {{-- Sélecteur de bien --}}
            @if(count($properties) > 1)
                <div class="de-card" style="display:flex;align-items:center;gap:12px;">
                    <label style="font-weight:600;font-size:14px;">Bien :</label>
                    <select
                        class="de-select"
                        @change="$wire.set('propertyId', parseInt($event.target.value))"
                    >
                        @foreach($properties as $prop)
                            <option value="{{ $prop['id'] }}" @if($prop['id'] === $propertyId) selected @endif>
                                {{ $prop['name'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            {{-- Bascule ventilation / montants --}}
            <div class="de-modes">
                <button class="de-mode-btn" :class="mode === 'ventilation' && 'de-mode-btn-active'" @click="setMode('ventilation')">
                    Ventilation
                </button>
                <button class="de-mode-btn" :class="mode === 'amounts' && 'de-mode-btn-active'" @click="setMode('amounts')">
                    Montants
                </button>
            </div>

            {{-- KPIs --}}
            <div class="de-grid de-grid-4">
                <div class="de-card de-stat de-stat-blue">
                    <div class="de-stat-value" x-text="formatEuros(depreciableBase)"></div>
                    <div class="de-stat-label">Base amortissable</div>
                </div>
                <div class="de-card de-stat" :class="getTotalClass()">
                    <div class="de-stat-value" x-text="formatEuros(getAllocatedCents() / 100)"></div>
                    <div class="de-stat-label">Ventilé</div>
                </div>
                <div class="de-card de-stat de-stat-green">
                    <div class="de-stat-value" x-text="formatEuros(getTotalAnnualDepreciation())"></div>
                    <div class="de-stat-label">Amortissement annuel</div>
                </div>
                <div class="de-card de-stat de-stat-blue">
                    <div class="de-stat-value" x-text="getWeightedDuration() + ' ans'"></div>
                    <div class="de-stat-label">Durée moyenne pondérée</div>
                </div>
            </div>

            {{-- Layout principal --}}
            <div class="de-grid de-grid-main" x-show="mode === 'ventilation'">
                {{-- Colonne gauche : composants --}}
                <div>
                    {{-- Standards --}}
                    <div class="de-card">
                        <div class="de-section-title">Composants standards</div>
                        <template x-for="(comp, idx) in components" :key="idx">
                            <div x-show="!comp.optional" class="de-comp">
                                <input
                                    type="checkbox"
                                    class="de-comp-checkbox"
                                    :checked="comp.enabled"
                                    @change="toggleStandard(idx)"
                                >
                                <div>
                                    <div class="de-comp-name">
                                        <span class="de-comp-emoji" x-text="getEmoji(comp.name)"></span>
                                        <span x-text="comp.name"></span>
                                    </div>
                                    <div class="de-comp-row">
                                        <div class="de-slider-container">
                                            <input
                                                type="range"
                                                class="de-slider"
                                                min="0" max="100" step="1"
                                                :value="Math.round(comp.percentage)"
                                                @pointerdown="startDrag(idx)"
                                                @input="onSlider(idx, parseInt($event.target.value))"
                                                :disabled="!comp.enabled"
                                            >
                                        </div>
                                        <span class="de-pct" x-text="formatPct(comp.percentage)"></span>
                                        <input
                                            type="number"
                                            class="de-duration-input"
                                            min="1" max="100"
                                            :value="comp.duration"
                                            @change="comp.duration = parseInt($event.target.value); markDirty()"
                                            :disabled="!comp.enabled"
                                        >
                                        <span class="de-duration-label">ans</span>
                                        <span class="de-amount" x-text="formatEuros(baseCentsOf(comp) / 100)"></span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Optionnels --}}
                    <div class="de-card">
                        <div class="de-section-title">Composants optionnels (maison)</div>
                        <template x-for="(comp, idx) in components" :key="idx">
                            <div x-show="comp.optional && !comp.custom" class="de-comp" :class="!comp.enabled && 'de-comp-disabled'">
                                <input
                                    type="checkbox"
                                    class="de-comp-checkbox"
                                    :checked="comp.enabled"
                                    @change="toggleOptional(idx)"
                                >
                                <div>
                                    <div class="de-comp-name">
                                        <span class="de-comp-emoji" x-text="getEmoji(comp.name)"></span>
                                        <span x-text="comp.name"></span>
                                    </div>
                                    <div class="de-comp-row">
                                        <div class="de-slider-container">
                                            <input
                                                type="range"
                                                class="de-slider"
                                                min="0" max="100" step="1"
                                                :value="Math.round(comp.percentage)"
                                                @pointerdown="startDrag(idx)"
                                                @input="onSlider(idx, parseInt($event.target.value))"
                                                :disabled="!comp.enabled"
                                            >
                                        </div>
                                        <span class="de-pct" x-text="formatPct(comp.percentage)"></span>
                                        <input
                                            type="number"
                                            class="de-duration-input"
                                            min="1" max="100"
                                            :value="comp.duration"
                                            @change="comp.duration = parseInt($event.target.value); markDirty()"
                                            :disabled="!comp.enabled"
                                        >
                                        <span class="de-duration-label">ans</span>
                                        <span class="de-amount" x-text="comp.enabled ? formatEuros(baseCentsOf(comp) / 100) : '—'"></span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- Composants à nom libre : le plan d'un cabinet ne se limite pas au
                         catalogue. Ils vivent dans leur propre carte pour que leur nom
                         reste modifiable, ce que les lignes du catalogue ne sont pas. --}}
                    <div class="de-card" x-show="components.some(c => c.custom)" x-cloak>
                        <div class="de-custom-title">Composants personnalisés</div>
                        <template x-for="(comp, idx) in components" :key="idx">
                            <div x-show="comp.custom" class="de-comp" :class="!comp.enabled && 'de-comp-disabled'">
                                <input
                                    type="checkbox"
                                    class="de-comp-checkbox"
                                    :checked="comp.enabled"
                                    @change="toggleStandard(idx)"
                                >
                                <div>
                                    <div class="de-comp-name">
                                        <input
                                            type="text"
                                            class="de-name-input"
                                            :value="comp.name"
                                            maxlength="120"
                                            placeholder="Nom du composant"
                                            @change="renameComponent(idx, $event.target.value)"
                                        >
                                        <button class="de-row-remove" title="Retirer ce composant" @click="removeComponent(idx)">&times;</button>
                                    </div>
                                    <div class="de-comp-row">
                                        <div class="de-slider-container">
                                            <input
                                                type="range"
                                                class="de-slider"
                                                min="0" max="100" step="1"
                                                :value="Math.round(comp.percentage)"
                                                @pointerdown="startDrag(idx)"
                                                @input="onSlider(idx, parseInt($event.target.value))"
                                                :disabled="!comp.enabled"
                                            >
                                        </div>
                                        <span class="de-pct" x-text="formatPct(comp.percentage)"></span>
                                        <input
                                            type="number"
                                            class="de-duration-input"
                                            min="1" max="100"
                                            :value="comp.duration"
                                            @change="comp.duration = parseInt($event.target.value); markDirty()"
                                            :disabled="!comp.enabled"
                                        >
                                        <span class="de-duration-label">ans</span>
                                        <span class="de-amount" x-text="comp.enabled ? formatEuros(baseCentsOf(comp) / 100) : '—'"></span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="de-card de-add-row">
                        <button class="de-btn-add" @click="addComponent()">+ Ajouter un composant</button>
                        <span class="de-hint" style="margin-bottom:0;">
                            Pour une ligne de votre liasse qui ne figure pas au catalogue
                            (« Ascenseur », « Menuiseries extérieures »&hellip;). Vous choisissez son nom,
                            sa durée et la ligne du 2033-C à laquelle elle se rattache, dans l'onglet
                            <strong>Montants</strong>.
                        </span>
                    </div>

                </div>

                {{-- Colonne droite : camembert --}}
                <div>
                    <div class="de-card de-chart-container">
                        <div class="de-section-title">Répartition</div>
                        <canvas x-ref="doughnutCanvas" style="max-height:300px;"></canvas>
                        <div class="de-chart-legend">
                            <template x-for="(item, i) in getEnabledComponents()" :key="item.name">
                                <div class="de-chart-legend-item">
                                    <span class="de-chart-legend-dot" :style="'background:' + chartColors[i % chartColors.length]"></span>
                                    <span x-text="getEmoji(item.name)"></span>
                                    <span x-text="item.name"></span>
                                    <span style="margin-left:auto;font-weight:600;font-family:monospace;" x-text="formatPct(pctOf(item))"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Mode montants : saisie directe des bases, pour reprendre une comptabilité existante --}}
            <div class="de-card" x-show="mode === 'amounts'" x-cloak>
                <div class="de-section-title">Montants par composant</div>
                <p class="de-hint">
                    Saisissez la base amortissable de chaque composant telle qu'elle figure dans votre
                    comptabilité. Les montants sont en euros, <strong>quote-part déjà appliquée</strong> :
                    c'est la part réellement louée qui s'amortit. La dotation annuelle se calcule
                    automatiquement, mais reste modifiable si votre cabinet arrondissait autrement.
                </p>
                <p class="de-hint">
                    <strong>Ligne 2033-C</strong> : la rubrique d'immobilisations à laquelle la ligne se
                    rattache dans la liasse. <strong>Début</strong> : à ne renseigner que si le composant
                    ne démarre pas à la mise en location du bien (passage du micro-BIC au réel, mise en
                    service échelonnée). <strong>Cumul repris</strong> : les amortissements déjà pratiqués
                    par votre cabinet sur des exercices que vous ne saisirez pas ici — ils s'ajoutent au
                    cumul du bilan, jamais à la charge de l'exercice.
                </p>
                <div class="de-amounts-wrap">
                <table class="de-amounts">
                    <thead>
                        <tr>
                            <th>Composant</th>
                            <th>Ligne 2033-C</th>
                            <th class="de-amounts-num">Base (&euro;)</th>
                            <th class="de-amounts-num">Durée</th>
                            <th class="de-amounts-num">Dotation annuelle (&euro;)</th>
                            <th>Début</th>
                            <th class="de-amounts-num">Cumul repris (&euro;)</th>
                            <th class="de-amounts-num">Part</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(comp, idx) in components" :key="idx">
                            <tr x-show="comp.enabled || comp.baseAmount > 0">
                                <td>
                                    <template x-if="!comp.custom">
                                        <span>
                                            <span x-text="getEmoji(comp.name)"></span>
                                            <span x-text="comp.name"></span>
                                        </span>
                                    </template>
                                    <template x-if="comp.custom">
                                        <input
                                            type="text" class="de-name-input" maxlength="120"
                                            placeholder="Nom du composant"
                                            :value="comp.name"
                                            @change="renameComponent(idx, $event.target.value)"
                                        >
                                    </template>
                                    <span class="de-manual-badge" x-show="comp.baseSource === 'manual'" x-cloak>saisi</span>
                                </td>
                                <td>
                                    <select class="de-cerfa-select" @change="setCerfaCategory(idx, $event.target.value)">
                                        <template x-for="(label, key) in cerfaCategories" :key="key">
                                            <option :value="key" :selected="key === comp.cerfaCategory" x-text="label"></option>
                                        </template>
                                    </select>
                                </td>
                                <td class="de-amounts-num">
                                    <input
                                        type="number" class="de-amount-input" min="0" step="0.01"
                                        :value="(baseCentsOf(comp) / 100).toFixed(2)"
                                        @change="setBaseEuros(idx, $event.target.value)"
                                    >
                                </td>
                                <td class="de-amounts-num">
                                    <input
                                        type="number" class="de-duration-input" min="1" max="100"
                                        :value="comp.duration"
                                        @change="comp.duration = parseInt($event.target.value) || 1; syncAnnual(idx); markDirty()"
                                    >
                                </td>
                                <td class="de-amounts-num">
                                    <input
                                        type="number" class="de-amount-input" min="0" step="0.01"
                                        :value="(annualCentsOf(comp) / 100).toFixed(2)"
                                        @change="setAnnualEuros(idx, $event.target.value)"
                                    >
                                </td>
                                <td>
                                    <input
                                        type="date" class="de-date-input"
                                        :value="comp.startDate || ''"
                                        :placeholder="rentalStartDate"
                                        @change="setStartDate(idx, $event.target.value)"
                                    >
                                </td>
                                <td class="de-amounts-num">
                                    <input
                                        type="number" class="de-amount-input" min="0" step="0.01"
                                        :value="((comp.openingCumul || 0) / 100).toFixed(2)"
                                        @change="setOpeningEuros(idx, $event.target.value)"
                                    >
                                </td>
                                <td class="de-amounts-num" x-text="formatPct(pctOf(comp))"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>

                <div class="de-add-row">
                    <button class="de-btn-add" @click="addComponent()">+ Ajouter un composant</button>
                </div>

                <div class="de-remainder" :class="remainderClass()" x-text="remainderLabel()"></div>
            </div>

            {{-- Actions --}}
                    <div class="de-card de-actions">
                        @if($showReset)
                            <button class="de-btn de-btn-secondary" @click="confirmReset()">
                                Réinitialiser par défaut
                            </button>
                        @else
                            <span></span>
                        @endif
                        <div style="display:flex;align-items:center;gap:12px;">
                            <span class="de-dirty-badge" x-show="isDirty" x-cloak>Modifications non enregistrées</span>
                            {{-- Plus jamais désactivé : sous-ventiler est légitime, et le
                                 serveur refuse la sur-ventilation avec un message explicite. --}}
                            <button class="de-btn de-btn-primary" @click="save()">
                                {{ $saveLabel }}
                            </button>
                        </div>
                    </div>
        </div>

    @endif
