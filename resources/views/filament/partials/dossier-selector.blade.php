@php
    // Le sélecteur ne se propose qu'au PRINCIPAL : on ne bascule jamais d'un dossier
    // vers un autre directement (App\Support\DossierContext::switchTo() le refuse).
    $principal = auth()->user();
    $dossiers = ($principal && ! $principal->is_linked_dossier)
        ? \App\Support\DossierContext::dossiersFor($principal)
        : collect();
@endphp
@if($dossiers->isNotEmpty())
    <style>
        .dossier-selector { padding: 6px 12px 10px; border-bottom: 1px solid var(--olmnp-border); }
        .dossier-selector-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--olmnp-fg-muted); padding: 4px 4px 6px; }
        .dossier-selector-item { width: 100%; text-align: left; padding: 6px 8px; border-radius: 8px; border: none; background: transparent; font-size: 13px; color: var(--olmnp-fg); cursor: pointer; }
        .dossier-selector-item:hover { background: var(--olmnp-surface-alt); }
    </style>
    <div class="dossier-selector">
        <div class="dossier-selector-title">Dossiers liés</div>
        @foreach($dossiers as $dossier)
            <form method="POST" action="{{ route('filament.admin.dossiers.open', ['dossier' => $dossier->public_id]) }}">
                @csrf
                @include('filament.partials.dossier-context-field')
                <button type="submit" class="dossier-selector-item">
                    {{ $dossier->user?->name }}
                </button>
            </form>
        @endforeach
    </div>
@endif
