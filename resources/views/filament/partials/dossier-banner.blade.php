@php
    /**
     * Écran 6, variante B (+ rappel de la variante C) — une seule vue, trois
     * emplacements (`$slot`), pour ne pas disperser « on est dans un dossier lié »
     * dans plusieurs fichiers :
     *   - `topbar`       : bandeau plein, en haut de chaque page (BODY_START) ;
     *   - `sidebar`      : carte d'identité, en tête de la barre latérale (SIDEBAR_START) ;
     *   - `form-reminder`: rappel sticky en bas d'écran, sur les pages Create/Edit
     *                      (BODY_END — voir le commentaire d'AdminPanelProvider).
     *
     * Couleur : ÉMERAUDE (jetons `--olmnp-success-*`), jamais l'ambre — l'ambre reste
     * réservé aux mises en garde ailleurs dans l'app, et être dans un dossier lié n'en
     * est pas une. Décision de Jérémie du 2026-10-15 (la maquette proposait l'indigo,
     * absent des jetons OpenLMNP).
     */
    $slot ??= 'topbar';
    $active = auth()->user();
@endphp
@if($active && $active->is_linked_dossier)
    @php
        $linkedDossier = $active->linkedDossier;
        $principal = \App\Support\DossierContext::principal($active);
        $autres = $linkedDossier
            ? $linkedDossier->members
                ->filter(fn ($m) => ! $m->revoked_at && $m->user_id !== $principal?->id)
                ->map(fn ($m) => $m->user?->name ?: $m->user?->email)
                ->filter()
                ->values()
            : collect();
    @endphp

    @if($slot === 'topbar')
        <style>
            .db-banner { position: sticky; top: 0; z-index: 40; display: flex; align-items: center;
                justify-content: space-between; gap: 12px; padding: 8px 16px; font-size: 13px;
                background: var(--olmnp-success-solid); color: var(--olmnp-on-solid);
                border-bottom: 1px solid var(--olmnp-success-solid-hover); flex-wrap: wrap; }
            .db-banner-id { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
            .db-pill { flex: none; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700;
                background: var(--olmnp-on-solid); color: var(--olmnp-success-solid-hover); }
            .db-name { font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .db-siren { opacity: .85; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
            .db-back { flex: none; display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px;
                border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; border: none;
                background: var(--olmnp-on-solid); color: var(--olmnp-success-solid-hover); }
            .db-back:focus-visible { outline: 2px solid var(--olmnp-on-solid); outline-offset: 2px; }
            /* Cadre de fenêtre : discret, pour qu'on ne confonde jamais ce dossier avec le
               dossier personnel même en périphérie de vision. */
            html:has(.db-banner) body { outline: 3px solid var(--olmnp-success-solid); outline-offset: -3px; }
        </style>
        <div class="db-banner" role="status">
            <span class="db-banner-id">
                <span class="db-pill">Dossier d'indivision</span>
                <span class="db-name">{{ $active->name }}</span>
                @if($active->siren)
                    <span class="db-siren">· SIREN {{ $active->siren }}</span>
                @endif
            </span>
            <form method="POST" action="{{ route('filament.admin.dossiers.leave') }}">
                @csrf
                @include('filament.partials.dossier-context-field')
                <button type="submit" class="db-back">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/></svg>
                    Revenir à mon dossier
                </button>
            </form>
        </div>
    @elseif($slot === 'sidebar')
        <style>
            .db-side-id { margin: 8px 12px 10px; padding: 10px; border-radius: 10px;
                background: var(--olmnp-success-bg); border: 1px solid var(--olmnp-success-border); }
            .db-side-id b { display: block; font-size: 13px; color: var(--olmnp-success-fg); margin-bottom: 2px; }
            .db-side-id span { font-size: 11px; color: var(--olmnp-fg-muted); }
        </style>
        <div class="db-side-id">
            <b>{{ $active->name }}</b>
            <span>{{ $autres->isNotEmpty() ? 'Dossier d\'indivision, partagé avec '.$autres->implode(', ') : 'Dossier d\'indivision' }}</span>
        </div>
    @elseif($slot === 'form-reminder')
        {{--
            Rappel de la variante C, SEULEMENT sur les pages de saisie (Create/Edit) : une
            heuristique sur le nom de route, faute d'un render hook Filament qui cible
            précisément « la barre d'actions de CE formulaire » (voir le commentaire
            d'AdminPanelProvider). Absent des pages de consultation, comme la maquette.
        --}}
        @php
            $routeName = (string) (request()->route()?->getName() ?? '');
            $isFormPage = str_ends_with($routeName, '.create') || str_contains($routeName, '.edit')
                || $routeName === 'filament.admin.auth.profile';
        @endphp
        @if($isFormPage)
            <style>
                .db-reminder { position: sticky; bottom: 0; z-index: 30; display: flex; align-items: center;
                    gap: 8px; padding: 8px 16px; font-size: 12px; background: var(--olmnp-success-bg-strong);
                    color: var(--olmnp-success-fg); border-top: 1px solid var(--olmnp-success-border); }
            </style>
            <div class="db-reminder" role="status">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                <span>Sera enregistrée dans <b>{{ $active->name }}</b>, pas dans votre dossier personnel.</span>
            </div>
        @endif
    @endif
@endif
