{{--
    Feuille de style et composant Alpine de l'éditeur d'amortissements.

    ⚠️ SÉPARÉ DU MARKUP, ET C'EST LE POINT IMPORTANT. `Alpine.data('depreciationEditor', …)`
    s'enregistre sur `alpine:init`, qui ne se produit QU'UNE FOIS, au chargement de la page.
    Livewire n'exécute pas les `<script>` qu'il injecte par morphing : quand l'éditeur
    apparaît en cours de parcours (étape 3 de l'assistant de reprise), le composant n'était
    jamais enregistré et l'écran restait vide — « depreciationEditor is not defined » dans la
    console, et rien à l'écran pour le dire.

    Ce partial doit donc être inclus DÈS LE PREMIER RENDU de la page hôte, même quand
    l'éditeur n'est pas encore affiché.
--}}
    <style>
        .de-card { background: var(--olmnp-surface); border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--olmnp-border); margin-bottom: 16px; }
        .de-grid { display: grid; gap: 12px; }
        .de-grid-4 { grid-template-columns: repeat(4, 1fr); }
        .de-grid-main { grid-template-columns: 1fr 380px; }
        .de-stat { text-align: center; }
        .de-stat-value { font-size: 22px; font-weight: 700; }
        .de-stat-label { font-size: 11px; color: var(--olmnp-fg-muted); margin-top: 4px; }
        .de-stat-green .de-stat-value { color: var(--olmnp-success-accent); }
        .de-stat-amber .de-stat-value { color: var(--olmnp-warning-fg); }
        .de-stat-red .de-stat-value { color: var(--olmnp-danger-accent); }
        .de-stat-blue .de-stat-value { color: var(--olmnp-info-fg); }
        .de-select { padding: 6px 10px; border: 1px solid var(--olmnp-border-strong); border-radius: 8px; font-size: 14px; background: var(--olmnp-surface); color: var(--olmnp-fg); }
        .de-section-title { font-size: 13px; font-weight: 700; color: var(--olmnp-fg-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--olmnp-border); }

        .de-comp { display: grid; grid-template-columns: 28px 1fr; gap: 12px; align-items: start; padding: 12px 0; border-bottom: 1px solid var(--olmnp-border); }
        .de-comp:last-child { border-bottom: none; }
        .de-comp-disabled { opacity: 0.45; }
        .de-comp-checkbox { width: 18px; height: 18px; accent-color: var(--olmnp-success-accent); cursor: pointer; margin-top: 3px; }
        .de-comp-name { font-weight: 600; font-size: 14px; color: var(--olmnp-fg); }
        .de-comp-emoji { margin-right: 6px; }
        .de-comp-row { display: flex; align-items: center; gap: 12px; margin-top: 6px; flex-wrap: wrap; }

        .de-slider-container { flex: 1; min-width: 120px; display: flex; align-items: center; gap: 8px; }
        .de-slider { -webkit-appearance: none; appearance: none; width: 100%; height: 6px; border-radius: 3px; background: var(--olmnp-border); outline: none; cursor: pointer; }
        .de-slider::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 18px; height: 18px; border-radius: 50%; background: var(--olmnp-success-solid); cursor: pointer; border: 2px solid white; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
        .de-slider::-moz-range-thumb { width: 18px; height: 18px; border-radius: 50%; background: var(--olmnp-success-solid); cursor: pointer; border: 2px solid white; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
        .de-slider:disabled { opacity: 0.3; cursor: not-allowed; }
        .de-pct { font-family: monospace; font-weight: 700; font-size: 15px; min-width: 42px; text-align: right; color: var(--olmnp-fg); }
        .de-duration-input { width: 52px; padding: 3px 6px; border: 1px solid var(--olmnp-border-strong); border-radius: 6px; font-size: 13px; text-align: center; background: var(--olmnp-surface); color: var(--olmnp-fg); }
        .de-duration-label { font-size: 12px; color: var(--olmnp-fg-muted); }
        .de-amount { font-family: monospace; font-size: 13px; color: var(--olmnp-fg-muted); min-width: 90px; text-align: right; }

        .de-actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .de-btn { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.2s; }
        .de-btn-primary { background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); }
        .de-btn-primary:hover { background: var(--olmnp-success-solid-hover); }
        .de-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .de-btn-secondary { background: transparent; color: var(--olmnp-fg-muted); border: 1px solid var(--olmnp-border-strong); }
        .de-btn-secondary:hover { background: var(--olmnp-surface-muted); }

        .de-chart-container { position: sticky; top: 80px; }
        .de-chart-legend { margin-top: 16px; }
        .de-chart-legend-item { display: flex; align-items: center; gap: 8px; font-size: 12px; padding: 3px 0; color: var(--olmnp-fg); }
        .de-chart-legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

        .de-dirty-badge { display: inline-block; background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px; margin-left: 8px; }

        @media (max-width: 1024px) {
            .de-grid-4 { grid-template-columns: repeat(2, 1fr); }
            .de-grid-main { grid-template-columns: 1fr; }
            .de-chart-container { position: static; }
        }
        @media (max-width: 640px) {
            .de-grid-4 { grid-template-columns: 1fr; }
            .de-comp-row { flex-direction: column; align-items: stretch; }
            .de-slider-container { min-width: 100%; }
        }

        /* Bascule ventilation / montants — toutes ces classes doivent être déclarées
           ici : aucun utilitaire Tailwind ne fonctionne dans le panel Filament. */
        .de-modes { display: inline-flex; gap: 0; background: var(--olmnp-surface-muted); border-radius: 10px; padding: 4px; margin-bottom: 16px; }
        .de-mode-btn { padding: 8px 18px; border: none; background: transparent; border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--olmnp-fg-muted); cursor: pointer; }
        .de-mode-btn:hover { color: var(--olmnp-fg); }
        .de-mode-btn-active { background: var(--olmnp-surface); color: var(--olmnp-success-accent); box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        .de-amounts { width: 100%; border-collapse: collapse; font-size: 14px; }
        .de-amounts th { text-align: left; font-size: 12px; font-weight: 700; color: var(--olmnp-fg-muted); text-transform: uppercase; letter-spacing: .05em; padding: 8px 10px; border-bottom: 1px solid var(--olmnp-border); }
        .de-amounts td { padding: 8px 10px; border-bottom: 1px solid var(--olmnp-border); vertical-align: middle; }
        .de-amounts tr:last-child td { border-bottom: none; }
        .de-amounts-num { text-align: right; font-family: monospace; }
        .de-amount-input { width: 130px; padding: 6px 8px; border: 1px solid var(--olmnp-border-strong); border-radius: 6px; font-size: 13px; text-align: right; font-family: monospace; background: var(--olmnp-surface); color: var(--olmnp-fg); }
        .de-amount-input:focus { outline: 2px solid var(--olmnp-success-solid); outline-offset: -1px; }
        .de-manual-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; background: var(--olmnp-info-bg); color: var(--olmnp-info-fg); border: 1px solid var(--olmnp-info-border); }
        .de-remainder { margin-top: 12px; padding: 10px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; }
        .de-remainder-ok { background: var(--olmnp-success-bg); color: var(--olmnp-success-fg); border: 1px solid var(--olmnp-success-border); }
        .de-remainder-under { background: var(--olmnp-warning-bg); color: var(--olmnp-warning-fg); border: 1px solid var(--olmnp-warning-border); }
        .de-remainder-over { background: var(--olmnp-danger-bg); color: var(--olmnp-danger-fg); border: 1px solid var(--olmnp-danger-border); }
        .de-hint { font-size: 12px; color: var(--olmnp-fg-muted); margin-bottom: 12px; line-height: 1.5; }

        /* Reprise d'un plan de cabinet : nom libre, ligne Cerfa, date de départ, cumul repris. */
        .de-amounts-wrap { overflow-x: auto; }
        .de-name-input { width: 190px; padding: 6px 8px; border: 1px solid var(--olmnp-border-strong); border-radius: 6px; font-size: 13px; background: var(--olmnp-surface); color: var(--olmnp-fg); }
        .de-cerfa-select { width: 160px; padding: 6px 8px; border: 1px solid var(--olmnp-border-strong); border-radius: 6px; font-size: 12px; background: var(--olmnp-surface); color: var(--olmnp-fg); }
        .de-date-input { width: 140px; padding: 6px 8px; border: 1px solid var(--olmnp-border-strong); border-radius: 6px; font-size: 13px; background: var(--olmnp-surface); color: var(--olmnp-fg); }
        .de-row-remove { border: none; background: transparent; color: var(--olmnp-danger-accent); font-size: 16px; font-weight: 700; cursor: pointer; line-height: 1; padding: 0 6px; }
        .de-add-row { margin-top: 12px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .de-btn-add { padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; background: transparent; color: var(--olmnp-success-accent); border: 1px dashed var(--olmnp-success-border); }
        .de-btn-add:hover { background: var(--olmnp-success-bg); }
        .de-custom-title { font-size: 13px; font-weight: 700; color: var(--olmnp-fg-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--olmnp-border); }
    </style>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('depreciationEditor', (initialData) => ({
                    components: [],
                    mode: 'ventilation',
                    depreciableBase: 0,
                    // Tout le raisonnement de ventilation se fait en CENTIMES ENTIERS.
                    // Les euros flottants perdaient des centimes dès que la base n'était
                    // pas divisible par 100, et l'écart se voyait à l'enregistrement.
                    depreciableBaseCents: 0,
                    cerfaCategories: {},
                    rentalStartDate: '',
                    chart: null,
                    isDirty: false,
                    savedState: '',

                    chartColors: [
                        '#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6',
                        '#06b6d4', '#ec4899', '#f97316', '#14b8a6', '#6366f1', '#84cc16'
                    ],

                    emojiMap: {
                        'Gros \u0153uvre': '\u{1F3D7}\uFE0F',
                        'Toiture': '\u{1F3E0}',
                        'Installations \u00E9lectriques': '\u26A1',
                        '\u00C9tanch\u00E9it\u00E9': '\u2600\uFE0F',
                        'Agencements int\u00E9rieurs': '\u{1F3A8}',
                        'Plomberie / sanitaire': '\u{1F6BF}',
                        'Piscine': '\u{1F3CA}',
                        'Climatisation / chauffage': '\u2744\uFE0F',
                        'Cuisine \u00E9quip\u00E9e': '\u{1F373}',
                        'VRD (voirie, r\u00E9seaux)': '\u{1F6A7}',
                        'Am\u00E9nagements ext\u00E9rieurs': '\u{1F333}',
                    },

                    init() {
                        this.loadData(initialData);
                        this.$nextTick(() => this.initChart());
                    },

                    reload(data) {
                        if (data && !data.empty) {
                            this.loadData(data);
                            this.$nextTick(() => this.updateChart());
                        }
                    },

                    loadData(data) {
                        this.components = JSON.parse(JSON.stringify(data.components));
                        this.depreciableBase = data.depreciableBase;
                        this.depreciableBaseCents = data.depreciableBaseCents;
                        this.cerfaCategories = data.cerfaCategories || {};
                        this.rentalStartDate = data.rentalStartDate || '';
                        // On ouvre sur le mode qui correspond aux données : quelqu'un qui a
                        // saisi ses montants ne doit pas retomber sur les curseurs.
                        // Un mode imposé par l'hôte (l'écran de choix de l'assistant de
                        // reprise) l'emporte ; sinon on ouvre sur le mode qui correspond
                        // aux données.
                        this.mode = data.initialMode
                            || (this.components.some(c => c.baseSource === 'manual')
                                ? 'amounts' : 'ventilation');
                        this.savedState = JSON.stringify(this.components);
                        this.isDirty = false;
                    },

                    getEmoji(name) {
                        return this.emojiMap[name] || '\u{1F4E6}';
                    },

                    setMode(next) {
                        this.mode = next;
                        // Chart.js ne supporte pas d'etre mis a jour sur un canvas masque :
                        // on attend que l'onglet Ventilation soit reellement visible.
                        if (next === 'ventilation') {
                            this.$nextTick(() => this.updateChart());
                        }
                    },

                    baseCentsOf(comp) {
                        return comp.baseSource === 'manual'
                            ? (comp.baseAmount || 0)
                            : Math.round(this.depreciableBaseCents * comp.percentage / 100);
                    },

                    annualCentsOf(comp) {
                        if (comp.baseSource === 'manual' && comp.annualDepreciation != null) {
                            return comp.annualDepreciation;
                        }
                        return comp.duration > 0 ? Math.floor(this.baseCentsOf(comp) / comp.duration) : 0;
                    },

                    pctOf(comp) {
                        if (!this.depreciableBaseCents) return 0;
                        return this.baseCentsOf(comp) * 100 / this.depreciableBaseCents;
                    },

                    setBaseEuros(idx, value) {
                        const comp = this.components[idx];
                        comp.baseAmount = Math.max(0, Math.round(parseFloat(value || 0) * 100));
                        comp.baseSource = 'manual';
                        comp.enabled = comp.baseAmount > 0;
                        comp.percentage = this.pctOf(comp);
                        comp.annualDepreciation = null;
                        this.markDirty();
                    },

                    setAnnualEuros(idx, value) {
                        const comp = this.components[idx];
                        comp.baseSource = 'manual';
                        if (comp.baseAmount == null) comp.baseAmount = this.baseCentsOf(comp);
                        comp.annualDepreciation = Math.max(0, Math.round(parseFloat(value || 0) * 100));
                        this.markDirty();
                    },

                    syncAnnual(idx) {
                        this.components[idx].annualDepreciation = null;
                    },

                    setCerfaCategory(idx, value) {
                        this.components[idx].cerfaCategory = value;
                        this.markDirty();
                    },

                    setStartDate(idx, value) {
                        // Chaîne vide et non null : le serveur distingue « vidé » (retour
                        // au défaut du bien) de « champ non envoyé » (on ne touche à rien).
                        this.components[idx].startDate = value || '';
                        this.markDirty();
                    },

                    setOpeningEuros(idx, value) {
                        this.components[idx].openingCumul = Math.max(0, Math.round(parseFloat(value || 0) * 100));
                        this.markDirty();
                    },

                    renameComponent(idx, value) {
                        const name = (value || '').trim();
                        if (name === '') return;
                        this.components[idx].name = name.slice(0, 120);
                        this.markDirty();
                    },

                    /**
                     * Ajoute une ligne à nom libre.
                     *
                     * Elle naît en mode « montants » : personne n'ajoute un composant hors
                     * catalogue pour le ventiler au curseur — on le fait pour recopier une
                     * ligne d'une liasse, avec son montant.
                     */
                    addComponent() {
                        const maxSort = this.components.reduce((m, c) => Math.max(m, c.sortOrder || 0), 0);

                        this.components.push({
                            id: null,
                            name: '',
                            percentage: 0,
                            baseAmount: 0,
                            baseSource: 'manual',
                            annualDepreciation: null,
                            duration: 10,
                            suggestedPercentage: 0,
                            optional: true,
                            custom: true,
                            enabled: true,
                            sortOrder: maxSort + 1,
                            cerfaCategory: 'autres',
                            startDate: null,
                            openingCumul: 0,
                        });

                        this.mode = 'amounts';
                        this.markDirty();
                    },

                    removeComponent(idx) {
                        const comp = this.components[idx];
                        const label = comp.name || 'ce composant';
                        if (! window.confirm('Retirer « ' + label + ' » du plan d\'amortissement ?')) return;
                        this.components.splice(idx, 1);
                        this.markDirty();
                    },

                    getAllocatedCents() {
                        return this.components
                            .filter(c => c.enabled)
                            .reduce((s, c) => s + this.baseCentsOf(c), 0);
                    },

                    getRemainderCents() {
                        return this.depreciableBaseCents - this.getAllocatedCents();
                    },

                    remainderClass() {
                        const r = this.getRemainderCents();
                        if (r < 0) return 'de-remainder-over';
                        // Quelques centimes d'écart ne sont que de la poussière de troncature.
                        return r <= this.components.length ? 'de-remainder-ok' : 'de-remainder-under';
                    },

                    remainderLabel() {
                        const r = this.getRemainderCents();
                        if (r < 0) {
                            return 'Sur-ventilation de ' + this.formatEuros(-r / 100)
                                + ' : l\'enregistrement sera refusé.';
                        }
                        if (r <= this.components.length) {
                            return 'La ventilation couvre exactement la base amortissable.';
                        }
                        return 'Reste à ventiler : ' + this.formatEuros(r / 100)
                            + '. C\'est permis, mais cette part ne s\'amortira pas.';
                    },

                    confirmReset() {
                        const manual = this.components.filter(c => c.baseSource === 'manual').length;
                        const message = manual > 0
                            ? 'Réinitialiser effacera ' + manual + ' base(s) saisie(s) à la main. Continuer ?'
                            : 'Restaurer les 6 composants standards ?';
                        if (window.confirm(message)) {
                            this.$wire.resetToDefaults();
                        }
                    },

                    getEnabledComponents() {
                        return this.components.filter(c => c.enabled && this.baseCentsOf(c) > 0);
                    },

                    getTotalPercentage() {
                        return this.components
                            .filter(c => c.enabled)
                            .reduce((s, c) => s + c.percentage, 0);
                    },

                    getTotalClass() {
                        const r = this.getRemainderCents();
                        if (r < 0) return 'de-stat-red';
                        return r <= this.components.length ? 'de-stat-green' : 'de-stat-amber';
                    },

                    getTotalAnnualDepreciation() {
                        return this.getEnabledComponents().reduce((s, c) => {
                            return s + this.annualCentsOf(c) / 100;
                        }, 0);
                    },

                    getWeightedDuration() {
                        const enabled = this.getEnabledComponents();
                        if (enabled.length === 0) return 0;
                        const totalPct = enabled.reduce((s, c) => s + c.percentage, 0);
                        if (totalPct === 0) return 0;
                        const weighted = enabled.reduce((s, c) => s + c.duration * c.percentage, 0);
                        return Math.round(weighted / totalPct);
                    },

                    formatEuros(val) {
                        return Math.round(val).toLocaleString('fr-FR') + ' \u20AC';
                    },

                    formatPct(val) {
                        return (val % 1 === 0 ? val : val.toFixed(1)) + ' %';
                    },

                    markDirty() {
                        this.components = this.components.map(c => ({...c}));
                        this.isDirty = JSON.stringify(this.components) !== this.savedState;
                        this.updateChart();
                    },

                    findGrosOeuvre() {
                        return this.components.find(c => c.name === 'Gros \u0153uvre');
                    },

                    /**
                     * Redistribue `amount` proportionnellement (en fractions).
                     * sign: +1 = ajouter, -1 = retirer.
                     */
                    distribute(targets, amount, sign) {
                        if (targets.length === 0 || amount === 0) return;
                        const total = targets.reduce((s, c) => s + c.percentage, 0);
                        if (total === 0) {
                            const each = amount / targets.length;
                            targets.forEach(c => { c.percentage += sign * each; });
                        } else {
                            targets.forEach(c => {
                                c.percentage += sign * (amount * c.percentage / total);
                                if (c.percentage < 0) c.percentage = 0;
                            });
                        }
                    },

                    toggleOptional(idx) {
                        const comp = this.components[idx];
                        comp.enabled = !comp.enabled;

                        if (comp.enabled) {
                            const pct = comp.suggestedPercentage;
                            comp.percentage = pct;
                            const others = this.components.filter((c, i) => i !== idx && c.enabled && c.percentage > 0);
                            this.distribute(others, pct, -1);
                        } else {
                            const freed = comp.percentage;
                            comp.percentage = 0;
                            const others = this.components.filter(c => c.enabled && c.percentage > 0);
                            this.distribute(others, freed, +1);
                        }
                        this._dragIdx = null;
                        this.markDirty();
                    },

                    toggleStandard(idx) {
                        const comp = this.components[idx];
                        comp.enabled = !comp.enabled;
                        if (!comp.enabled) {
                            const freed = comp.percentage;
                            comp.percentage = 0;
                            const others = this.components.filter(c => c.enabled && c.percentage > 0);
                            this.distribute(others, freed, +1);
                        } else {
                            const pct = comp.suggestedPercentage;
                            comp.percentage = pct;
                            const others = this.components.filter((c, i) => i !== idx && c.enabled && c.percentage > 0);
                            this.distribute(others, pct, -1);
                        }
                        this._dragIdx = null;
                        this.markDirty();
                    },

                    _dragIdx: null,
                    _dragOriginals: null,

                    startDrag(idx) {
                        this._dragIdx = idx;
                        this._dragOriginals = this.components.map(c => c.percentage);
                    },

                    onSlider(idx, newValue) {
                        // Snapshot si pas encore fait (fallback)
                        if (this._dragIdx !== idx || !this._dragOriginals) {
                            this._dragIdx = idx;
                            this._dragOriginals = this.components.map(c => c.percentage);
                        }

                        const origValue = this._dragOriginals[idx];
                        const diff = newValue - origValue;

                        // Recalculer TOUS les autres depuis l'original
                        this.components[idx].percentage = newValue;

                        let othersOrigTotal = 0;
                        for (let i = 0; i < this.components.length; i++) {
                            if (i !== idx && this.components[i].enabled && this._dragOriginals[i] > 0) {
                                othersOrigTotal += this._dragOriginals[i];
                            }
                        }

                        if (othersOrigTotal > 0) {
                            for (let i = 0; i < this.components.length; i++) {
                                if (i !== idx && this.components[i].enabled && this._dragOriginals[i] > 0) {
                                    const share = diff * this._dragOriginals[i] / othersOrigTotal;
                                    this.components[i].percentage = this._dragOriginals[i] - share;
                                    if (this.components[i].percentage < 0) this.components[i].percentage = 0;
                                }
                            }
                        }

                        this.markDirty();
                    },

                    initChart() {
                        const ctx = this.$refs.doughnutCanvas;
                        if (!ctx) return;

                        this.chart = new Chart(ctx, {
                            type: 'doughnut',
                            data: this.getChartData(),
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                cutout: '55%',
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: (context) => {
                                                const enabled = this.getEnabledComponents();
                                                const comp = enabled[context.dataIndex];
                                                if (!comp) return '';
                                                const base = Math.round(this.depreciableBase * comp.percentage / 100);
                                                const pct = comp.percentage % 1 === 0 ? comp.percentage : comp.percentage.toFixed(1);
                                                return ` ${pct} % \u2014 ${base.toLocaleString('fr-FR')} \u20AC`;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    },

                    getChartData() {
                        const enabled = this.getEnabledComponents();
                        return {
                            labels: enabled.map(c => this.getEmoji(c.name) + ' ' + c.name),
                            datasets: [{
                                data: enabled.map(c => this.baseCentsOf(c)),
                                backgroundColor: this.chartColors.slice(0, enabled.length),
                                borderWidth: 2,
                                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--olmnp-surface').trim() || '#fff',
                            }]
                        };
                    },

                    updateChart() {
                        // Chart.js casse quand on le met a jour sur un canvas masque par
                        // x-show (« Cannot set properties of undefined »). Le camembert
                        // vit dans l'onglet Ventilation : ailleurs, on ne fait rien, et
                        // setMode() le rafraichit au retour.
                        if (!this.chart || this.mode !== 'ventilation') return;
                        const data = this.getChartData();
                        this.chart.data.labels = data.labels;
                        this.chart.data.datasets[0].data = data.datasets[0].data;
                        this.chart.data.datasets[0].backgroundColor = data.datasets[0].backgroundColor;
                        this.chart.update('none');
                    },

                    save() {
                        // Plus d'arrondi de Hamilton ici : l'invariant se vérifie en
                        // centimes côté serveur, qui refuse la sur-ventilation et absorbe
                        // lui-même la poussière de troncature. Le JS n'a plus à corriger
                        // des pourcentages avant l'envoi.
                        const payload = this.components.map(c => ({
                            id: c.id,
                            name: c.name,
                            percentage: c.percentage,
                            baseAmount: this.baseCentsOf(c),
                            baseSource: c.baseSource,
                            annualDepreciation: c.baseSource === 'manual' ? this.annualCentsOf(c) : null,
                            duration: c.duration,
                            sortOrder: c.sortOrder,
                            enabled: c.enabled,
                            cerfaCategory: c.cerfaCategory,
                            startDate: c.startDate,
                            openingCumul: c.openingCumul || 0,
                        }));

                        this.$wire.saveComponents(payload).then(() => {
                            this.savedState = JSON.stringify(this.components);
                            this.isDirty = false;
                            this._dragIdx = null;
                            this.updateChart();
                        });
                    },
                }));
            });
        </script>
