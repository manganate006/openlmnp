{{--
    Bandeau des avis de sécurité (render hook CONTENT_START, administrateurs seulement).

    Les avis arrivent avec la réponse du check-in de télémétrie (SecurityAdvisoryService) :
    ce sont des données reçues du réseau, donc TOUT est échappé ({{ }}), jamais {!! !!}.
    Seuls les avis qui concernent la version installée sont passés à la vue : le bandeau
    disparaît de lui-même après la mise à jour. Pas de bouton de fermeture, volontairement.

    Couleurs : jetons de theme-tokens uniquement (PanelStylesheetTest refuse tout littéral).

    @var list<array{id: string, severity: string, title: string, message: string, url: ?string, affected_from: string, fixed_in: string}> $advisories
    @var string $updateCommand
    @var ?string $updateUrl
--}}
<style>
    .olmnp-advisories { display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1rem; }
    .olmnp-advisory {
        border: 1px solid var(--olmnp-warning-border);
        border-left-width: 4px;
        background: var(--olmnp-warning-bg);
        color: var(--olmnp-warning-fg);
        border-radius: 0.5rem;
        padding: 0.875rem 1rem;
        font-size: 0.875rem;
        line-height: 1.4rem;
    }
    .olmnp-advisory-critical, .olmnp-advisory-high {
        border-color: var(--olmnp-danger-border);
        background: var(--olmnp-danger-bg);
        color: var(--olmnp-danger-fg);
    }
    .olmnp-advisory-title { font-weight: 600; font-size: 0.9375rem; margin: 0 0 0.25rem; }
    .olmnp-advisory-badge {
        display: inline-block;
        font-size: 0.6875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        padding: 0 0.4rem;
        margin-right: 0.4rem;
        border-radius: 0.25rem;
        background: var(--olmnp-warning-solid);
        color: var(--olmnp-on-solid);
        vertical-align: 0.1em;
    }
    .olmnp-advisory-critical .olmnp-advisory-badge, .olmnp-advisory-high .olmnp-advisory-badge {
        background: var(--olmnp-danger-solid);
    }
    .olmnp-advisory-message { margin: 0 0 0.5rem; white-space: pre-line; }
    .olmnp-advisory-action { margin: 0; }
    .olmnp-advisory a { color: inherit; font-weight: 600; text-decoration: underline; }
    .olmnp-advisory code {
        background: var(--olmnp-code-bg);
        color: var(--olmnp-code-fg);
        border-radius: 0.25rem;
        padding: 0.05rem 0.35rem;
        font-size: 0.8125rem;
    }
</style>

@php
    $severityLabels = ['critical' => 'Critique', 'high' => 'Élevée', 'medium' => 'Moyenne', 'low' => 'Faible'];
@endphp

<div class="olmnp-advisories" role="alert" data-testid="security-advisories">
    @foreach ($advisories as $advisory)
        <div class="olmnp-advisory olmnp-advisory-{{ $advisory['severity'] }}" data-advisory-id="{{ $advisory['id'] }}">
            <p class="olmnp-advisory-title">
                <span class="olmnp-advisory-badge">Sécurité : {{ $severityLabels[$advisory['severity']] ?? $advisory['severity'] }}</span>
                {{ $advisory['title'] }}
            </p>
            <p class="olmnp-advisory-message">{{ $advisory['message'] }}</p>
            <p class="olmnp-advisory-action">
                Corrigé dans la version {{ $advisory['fixed_in'] }} : mettez à jour sans attendre.
                Avec Docker : <code>{{ $updateCommand }}</code>, puis recréez le conteneur avec la même commande.
                @if ($updateUrl)
                    <a href="{{ $updateUrl }}">Page Mises à jour</a>
                @endif
                @if ($advisory['url'])
                    <a href="{{ $advisory['url'] }}" target="_blank" rel="noopener noreferrer">Lire l'avis</a>
                @endif
            </p>
        </div>
    @endforeach
</div>
