<x-filament-panels::page>
    <style>
        .ai-card { background: var(--olmnp-surface); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--olmnp-border); }
        .ai-notice { border-radius: 12px; padding: 20px; border: 1px solid var(--olmnp-warning-border); background: var(--olmnp-warning-bg); color: var(--olmnp-warning-fg); font-size: 14px; }
        .ai-notice h4 { font-weight: 700; margin: 0 0 6px; font-size: 15px; }
        .ai-consent { border-radius: 12px; padding: 20px; border: 1px solid var(--olmnp-info-border); background: var(--olmnp-info-bg); color: var(--olmnp-info-fg); font-size: 14px; }
        .ai-consent h4 { font-weight: 700; margin: 0 0 8px; font-size: 15px; color: var(--olmnp-info-fg); }
        .ai-consent ul { margin: 8px 0 0; padding-left: 20px; }
        .ai-consent li { padding: 2px 0; }
        .ai-banner { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; justify-content: space-between; margin-bottom: 16px; }
        .ai-badge { display: inline-flex; align-items: center; padding: 3px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .ai-badge-high { background: var(--olmnp-success-bg-strong); color: var(--olmnp-success-fg); }
        .ai-badge-medium { background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); }
        .ai-badge-low { background: var(--olmnp-danger-bg-strong); color: var(--olmnp-danger-fg); }
        .ai-badge-model { background: var(--olmnp-surface-alt); color: var(--olmnp-fg-muted); font-family: ui-monospace, monospace; font-size: 11px; }
        .ai-split { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 20px; align-items: start; }
        @media (max-width: 1024px) { .ai-split { grid-template-columns: 1fr; } }
        .ai-viewer { width: 100%; height: 640px; border: 1px solid var(--olmnp-border); border-radius: 10px; background: var(--olmnp-surface-muted); }
        .ai-warning { margin-bottom: 12px; padding: 10px 14px; background: var(--olmnp-warning-bg); border: 1px solid var(--olmnp-warning-border); border-radius: 10px; font-size: 13px; color: var(--olmnp-warning-fg); }
        .ai-duplicate { margin-bottom: 12px; padding: 12px 16px; background: var(--olmnp-danger-bg); border: 1px solid var(--olmnp-danger-border); border-radius: 10px; font-size: 13px; color: var(--olmnp-danger-fg); }
        .ai-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
        .ai-btn { display: inline-flex; align-items: center; padding: 8px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; }
        .ai-btn-primary { background: var(--olmnp-success-solid); color: var(--olmnp-on-solid); }
        .ai-btn-primary:hover { background: var(--olmnp-success-solid-hover); }
        .ai-btn-secondary { background: var(--olmnp-surface-alt); color: var(--olmnp-fg); }
        .ai-btn-secondary:hover { background: var(--olmnp-border); }
        .ai-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .ai-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .ai-table th { background: var(--olmnp-surface-alt); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; }
        .ai-table th, .ai-table td { padding: 8px 12px; border-bottom: 1px solid var(--olmnp-border); text-align: left; }
        .ai-table td.num, .ai-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .ai-table tr.muted { opacity: 0.5; }
        .ai-scroll { max-height: 420px; overflow-y: auto; border: 1px solid var(--olmnp-border); border-radius: 8px; }
        .ai-quota { font-size: 12px; color: var(--olmnp-fg-muted); margin-top: 8px; }
        .ai-select { border-radius: 6px; border: 1px solid var(--olmnp-border-strong); padding: 3px 6px; font-size: 12px; background: var(--olmnp-surface); }
        /* ⚠️ Jetons seuls, aucune valeur en dur : une couleur littérale ne se
           décline pas en thème sombre, et PanelStylesheetTest la refuse — y
           compris en simple repli de var(). Il a raison : le repli sert
           précisément quand le jeton manque, c'est-à-dire quand le thème ne
           s'applique pas. */
        .ai-vault-warning {
            border: 1px solid var(--olmnp-warning-border);
            background: var(--olmnp-warning-bg);
            color: var(--olmnp-warning-fg);
            border-radius: .5rem;
            padding: .75rem 1rem;
            margin-bottom: 1rem;
        }

        .ai-vault-warning p { margin: .25rem 0 0; }

    </style>

    @php($unavailable = $this->unavailableMessage())

    {{-- Coffre-fort actif : dire que l'analyse contredit la promesse du chiffrement. --}}
    @php($vaultWarning = $this->vaultWarning())
    @if($vaultWarning && ! $unavailable)
        <div class="ai-vault-warning">
            <strong>Votre coffre-fort ne couvre pas l'analyse.</strong>
            <p>{{ $vaultWarning }}</p>
        </div>
    @endif

    {{-- Consentement : obligatoire avant le premier envoi à un tiers (CDC § 3.4) --}}
    @if($consentNeeded && ! $unavailable)
        <div class="ai-consent">
            <h4>Avant la première analyse</h4>
            <p>Pour lire vos justificatifs, OpenLMNP les transmet au service d'intelligence artificielle
                configuré sur cette instance, et à l'éditeur du modèle choisi.</p>
            <ul>
                <li><strong>Ce qui est transmis</strong> : le document déposé, le nom et l'adresse du bien concerné.</li>
                <li><strong>Ce qui ne l'est jamais</strong> : votre identité, votre adresse électronique,
                    votre SIREN, vos autres documents et vos autres données comptables.</li>
                <li><strong>Conservation</strong> : chez OpenRouter, chaque requête est accompagnée d'une
                    consigne de ne rien conserver et de ne pas s'en servir pour l'entraînement. C'est une
                    préférence transmise à un intermédiaire, pas un engagement que nous puissions tenir à sa
                    place ; ailleurs, cela relève des conditions du fournisseur.</li>
                <li><strong>En auto-hébergement</strong>, vous pouvez faire pointer l'application vers un modèle
                    installé chez vous : rien ne sort alors de votre serveur.</li>
            </ul>
            <p style="margin-top: 10px;">
                Détails dans notre <a href="/confidentialite" target="_blank" style="text-decoration: underline;">politique de confidentialité</a>.
            </p>
            <div class="ai-actions">
                <button wire:click="acceptConsent" class="ai-btn ai-btn-primary">J'ai compris</button>
            </div>
        </div>
    @endif

    {{-- États d'indisponibilité (CDC § 3.5) --}}
    @if($unavailable)
        <div class="ai-notice">
            <h4>Analyse indisponible</h4>
            <p>{{ $unavailable }}</p>
        </div>
    @endif

    @if(! $analysis)
        {{-- Étape 1 : dépôt --}}
        {{ $this->form }}

        @if($this->quotaLabel())
            <p class="ai-quota">{{ $this->quotaLabel() }}</p>
        @endif

        <div wire:loading wire:target="analyze" class="ai-card" style="margin-top: 16px;">
            <p style="margin: 0; font-weight: 600;">Analyse en cours…</p>
            <p style="margin: 4px 0 0; font-size: 13px; color: var(--olmnp-fg-muted);">
                La lecture d'un document prend généralement de 2 à 15 secondes.
            </p>
        </div>
    @else
        @php($result = $this->result())

        <div class="ai-split">
            <div class="ai-card">
                <div class="ai-banner">
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <select class="ai-select" wire:change="changeType($event.target.value)">
                            @foreach(\App\Filament\Pages\ImportDocument::typeOptions() as $value => $label)
                                @if($value !== '')
                                    <option value="{{ $value }}" @selected($result->documentType === $value)>{{ $label }}</option>
                                @endif
                            @endforeach
                        </select>
                        <span class="ai-badge ai-badge-{{ $result->confidenceBucket() }}">
                            Confiance {{ number_format($result->confidence * 100, 0) }} %
                        </span>
                        @if($result->fallbackUsed)
                            <span class="ai-badge ai-badge-model">modèle renforcé</span>
                        @endif
                    </div>
                    {{-- Proposé seulement quand il peut servir : sous le seuil de
                         confiance, et si la cascade n'a pas déjà eu lieu. --}}
                    @if($this->canReanalyze())
                        <button wire:click="reanalyzeStrong" wire:loading.attr="disabled" class="ai-btn ai-btn-secondary" style="padding: 6px 14px; font-size: 13px;">
                            <span wire:loading.remove wire:target="reanalyzeStrong">Réanalyser avec le modèle renforcé</span>
                            <span wire:loading wire:target="reanalyzeStrong">Analyse…</span>
                        </button>
                    @endif
                </div>

                @if($result->isUnrecognised())
                    <div class="ai-warning">
                        Ce document n'a pas été reconnu de façon fiable. Choisissez son type ci-dessus et
                        complétez les champs à la main : rien ne sera enregistré sans votre validation.
                    </div>
                @endif

                @foreach($result->warnings as $warning)
                    <div class="ai-warning">{{ $warning }}</div>
                @endforeach

                @if($result->duplicate)
                    <div class="ai-duplicate">
                        <strong>Doublon possible.</strong>
                        Une écriture du même montant existe déjà pour ce bien
                        @if($result->duplicate['date']) au {{ $result->duplicate['date'] }} @endif :
                        « {{ $result->duplicate['label'] }} »
                        ({{ number_format($result->duplicate['amount'] / 100, 2, ',', "\u{202F}") }} €).
                        Vérifiez avant d'enregistrer.
                    </div>
                @endif

                {{ $this->previewForm }}

                {{-- Lignes : articles de mobilier, échéances de prêt, opérations de relevé --}}
                @if(! empty($values['rows']))
                    @include('filament.pages.partials.import-document-rows', [
                        'entity' => $result->entity(),
                        'rows' => $values['rows'],
                    ])
                @endif

                <div class="ai-actions">
                    <button wire:click="confirm" wire:loading.attr="disabled" class="ai-btn ai-btn-primary">
                        <span wire:loading.remove wire:target="confirm">Créer</span>
                        <span wire:loading wire:target="confirm">Enregistrement…</span>
                    </button>
                    <button wire:click="confirmAndNext" wire:loading.attr="disabled" class="ai-btn ai-btn-secondary">
                        Créer et analyser un autre
                    </button>
                    <button wire:click="cancel" class="ai-btn ai-btn-secondary">Annuler</button>
                </div>
            </div>

            <div class="ai-card">
                <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 12px;">
                    <h3 style="font-size: 14px; font-weight: 700; margin: 0;">Document déposé</h3>
                    @if($this->previewUrl())
                        <a href="{{ $this->previewUrl() }}" target="_blank" rel="noopener"
                           style="font-size: 12px; text-decoration: underline;">Ouvrir dans un onglet</a>
                    @endif
                </div>
                @if($this->previewUrl() && $this->previewIsImage())
                    {{-- Une image n'a besoin ni de cadre ni de visionneuse. --}}
                    <img class="ai-viewer" src="{{ $this->previewUrl() }}"
                         alt="Aperçu du justificatif" style="object-fit: contain;">
                @elseif($this->previewUrl())
                    {{--
                        `allow-same-origin` est INDISPENSABLE : sans lui le cadre a une origine
                        opaque, le cookie de session n'est pas envoyé (SameSite) et
                        DocumentController refuse la requête. Les scripts restent bloqués, faute
                        d'`allow-scripts`.

                        C'est le contrôleur qui rend le fichier inoffensif, en imposant son
                        Content-Type depuis l'extension : un HTML déguisé en .pdf part en
                        application/pdf et ne s'exécute pas. Il ne pose donc plus de directive
                        CSP `sandbox` nue, qui donnait au document une origine opaque et
                        empêchait la visionneuse PDF de Chrome de s'ouvrir.
                    --}}
                    <iframe class="ai-viewer" src="{{ $this->previewUrl() }}"
                            sandbox="allow-same-origin" title="Aperçu du justificatif"></iframe>
                @else
                    <p style="font-size: 13px; color: var(--olmnp-fg-muted);">Aperçu indisponible.</p>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
