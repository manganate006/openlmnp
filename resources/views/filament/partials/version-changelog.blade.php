{{--
    Version installée, dernière version publiée et notes des dernières releases.

    Partagé par `filament/pages/whats-new` (tout utilisateur) et `filament/pages/admin-update`
    (administrateurs) — le patron est celui de `depreciation-editor-core` : un seul markup,
    la logique serveur dans le trait `App\Filament\Pages\Concerns\ShowsVersionInfo`.

    `wire:init` n'est posé QUE si le cache est froid : cache chaud = zéro aller-retour Livewire.
--}}
<div class="vc-wrap" @if($this->needsVersionFetch()) wire:init="refreshVersionInfo" @endif>
    <style>
        /* `display: contents` : ce conteneur ne porte que l'attribut de relevé, il ne doit
           pas s'interposer dans la mise en page. Ses cartes se comportent alors comme celles
           de la page hôte, espacement compris. */
        .vc-wrap { display: contents; }
        .vc-card { background: var(--olmnp-surface); border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.1); border: 1px solid var(--olmnp-border); margin-bottom: 16px; }
        .vc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
        .vc-label { font-size: 12px; font-weight: 600; color: var(--olmnp-fg-muted); text-transform: uppercase; letter-spacing: .04em; }
        .vc-version { font-size: 32px; font-weight: 800; color: var(--olmnp-success-accent); line-height: 1.1; }
        .vc-date { font-size: 12px; color: var(--olmnp-fg-subtle); margin-top: 2px; }
        .vc-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; background: var(--olmnp-success-bg); color: var(--olmnp-success-fg); }
        .vc-badge-new { background: var(--olmnp-warning-bg-strong); color: var(--olmnp-warning-fg); }
        .vc-badge-muted { background: var(--olmnp-surface-muted); color: var(--olmnp-fg-muted); }
        .vc-badge-error { background: var(--olmnp-danger-bg); color: var(--olmnp-danger-fg); }
        .vc-note { font-size: 13px; color: var(--olmnp-fg-muted); margin-top: 12px; }
        .vc-title { font-size: 16px; font-weight: 700; margin-bottom: 16px; color: var(--olmnp-fg-strong); }
        .vc-release { padding: 16px; background: var(--olmnp-surface-muted); border-radius: 8px; margin-bottom: 12px; }
        .vc-release:last-child { margin-bottom: 0; }
        .vc-release-head { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
        .vc-release-version { font-size: 14px; font-weight: 700; color: var(--olmnp-fg-strong); }
        .vc-release-date { font-size: 11px; color: var(--olmnp-fg-subtle); }
        .vc-tag { display: inline-flex; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; background: var(--olmnp-accent-bg); color: var(--olmnp-accent-fg); }
        .vc-tag-installed { background: var(--olmnp-success-bg); color: var(--olmnp-success-fg); }

        /* Notes rendues depuis le Markdown de GitHub : le HTML produit ne porte aucune
           classe, on l'habille donc par sélecteurs d'éléments. */
        .vc-notes { margin-top: 10px; font-size: 13px; color: var(--olmnp-fg); }
        .vc-notes h1, .vc-notes h2, .vc-notes h3, .vc-notes h4 { font-size: 13px; font-weight: 700; color: var(--olmnp-fg-strong); margin: 12px 0 4px; }
        .vc-notes h1:first-child, .vc-notes h2:first-child, .vc-notes h3:first-child, .vc-notes h4:first-child { margin-top: 0; }
        .vc-notes p { margin: 6px 0; line-height: 1.55; }
        .vc-notes ul, .vc-notes ol { margin: 6px 0 6px 18px; list-style: disc; }
        .vc-notes ol { list-style: decimal; }
        .vc-notes li { margin: 3px 0; line-height: 1.5; }
        .vc-notes strong { font-weight: 700; color: var(--olmnp-fg-strong); }
        .vc-notes em { font-style: italic; }
        .vc-notes a { color: var(--olmnp-accent-accent); text-decoration: underline; }
        .vc-notes code { font-family: monospace; font-size: 12px; padding: 1px 5px; border-radius: 4px; background: var(--olmnp-code-bg); color: var(--olmnp-code-fg); }
        .vc-notes pre { padding: 10px 12px; border-radius: 8px; background: var(--olmnp-code-bg); color: var(--olmnp-code-fg); overflow-x: auto; margin: 8px 0; }
        .vc-notes pre code { padding: 0; background: none; }
        .vc-notes blockquote { border-left: 3px solid var(--olmnp-border-strong); padding-left: 10px; color: var(--olmnp-fg-muted); margin: 8px 0; }
        .vc-notes hr { border: none; border-top: 1px solid var(--olmnp-border); margin: 12px 0; }
        .vc-notes img { max-width: 100%; height: auto; }
    </style>

    {{-- ================================================================== --}}
    {{-- Version installée                                                  --}}
    {{-- ================================================================== --}}
    <div class="vc-card">
        <div class="vc-head">
            <div>
                <div class="vc-label">Version installée</div>
                <div class="vc-version">
                    @if($this->versionIsKnown())
                        v{{ $this->currentVersion() }}
                    @else
                        inconnue
                    @endif
                </div>
                @if($this->releasedAt())
                    <div class="vc-date">publiée le {{ $this->releasedAt() }}</div>
                @endif
            </div>

            <div>
                @if(! $this->versionCheckEnabled())
                    <span class="vc-badge vc-badge-muted">Vérification désactivée</span>
                @elseif($updateInfo === null)
                    <span class="vc-badge vc-badge-muted">Vérification en cours&hellip;</span>
                @elseif(isset($updateInfo['error']))
                    <span class="vc-badge vc-badge-error">Vérification impossible</span>
                @elseif($updateInfo['available'] ?? false)
                    {{-- Rien du tout quand l'utilisateur ne peut pas agir : replier sur
                         « Version à jour » serait faux. --}}
                    @if($this->showsAvailableUpdateBadge())
                        <span class="vc-badge vc-badge-new">v{{ $updateInfo['latest_version'] }} disponible</span>
                    @endif
                @elseif(($updateInfo['info'] ?? '') === 'no_release')
                    <span class="vc-badge vc-badge-muted">Aucune release publiée</span>
                @else
                    <span class="vc-badge">Version à jour</span>
                @endif
            </div>
        </div>

        @if($this->versionCheckEnabled() && $updateInfo && isset($updateInfo['error']))
            <div class="vc-note">{{ $updateInfo['error'] }}</div>
        @endif
    </div>

    {{-- ================================================================== --}}
    {{-- Notes des dernières releases                                       --}}
    {{-- ================================================================== --}}
    <div class="vc-card">
        <h3 class="vc-title">Nouveautés des dernières versions</h3>

        @if(! $this->versionCheckEnabled())
            <p class="vc-note">
                Le relevé des versions publiées est désactivé sur cette instance
                (<code>UPDATE_CHECK_ENABLED=false</code>) : les notes de version ne peuvent pas
                être récupérées. Elles restent consultables dans le fichier
                <code>CHANGELOG.md</code> du dépôt.
            </p>
        @elseif($changelog === null)
            <p class="vc-note">Récupération des notes de version&hellip;</p>
        @elseif(count($changelog) === 0)
            <p class="vc-note">Aucune note de version n'a pu être récupérée pour le moment.</p>
        @else
            @foreach($changelog as $release)
                <div class="vc-release">
                    <div class="vc-release-head">
                        <span class="vc-release-version">v{{ $release['version'] }}</span>
                        {{-- Le nom d'une release répète souvent son numéro (« OpenLMNP »
                             suivi de la version) : on ne l'affiche que s'il apporte autre
                             chose. Pas d'exemple chiffré ici, le balayage des versions
                             écrites en dur le compterait comme une infraction — à raison,
                             un numéro dans un commentaire se périme comme ailleurs. --}}
                        @if(($release['name'] ?? '') !== '' && ! str_contains($release['name'], $release['version']))
                            <span class="vc-release-date">{{ $release['name'] }}</span>
                        @endif
                        @if($release['published_at'])
                            <span class="vc-release-date">{{ \Carbon\Carbon::parse($release['published_at'])->format('d/m/Y') }}</span>
                        @endif
                        @if($release['version'] === $this->currentVersion())
                            <span class="vc-tag vc-tag-installed">votre version</span>
                        @elseif($this->releaseIsNewerThanInstalled($release['version']))
                            <span class="vc-tag">plus récente</span>
                        @endif
                    </div>

                    @if($release['changelog'])
                        <div class="vc-notes">{!! $this->renderReleaseNotes($release['changelog']) !!}</div>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</div>
