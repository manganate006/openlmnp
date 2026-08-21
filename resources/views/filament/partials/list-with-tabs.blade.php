@php
    $tabLabels = ['general' => 'Général', 'works' => 'Travaux', 'furniture' => 'Mobilier', 'components' => 'Composants'];
    $currentLabel = $tabLabels[$active] ?? $heading;
    $properties = $properties ?? null;
    $currentUrl = $currentUrl ?? null;
@endphp

{{--
    ⚠️ Aucun utilitaire Tailwind n'existe dans le CSS du panel (cf. app/CLAUDE.md).
    La mise en forme vient donc des vraies classes Filament (`fi-breadcrumbs`, `fi-header`,
    `fi-header-heading`, `fi-header-actions-ctn`) et, pour le reste, du <style> scopé `lwt-*`.
    `.fi-header .fi-breadcrumbs` est une règle DESCENDANTE : le <nav> étant frère de `.fi-header`
    et non son descendant, sa marge basse et son masquage mobile ne s'appliquent pas ici.
--}}
<style>
    .lwt-crumbs { margin-bottom: 0.5rem; }
    .lwt-crumbs a:hover { text-decoration: underline; }
    /* Met en valeur le niveau courant : `.fi-breadcrumbs ol li` le laisse en gris 500 */
    .lwt-crumb-current { color: #030712; }
    .dark .lwt-crumb-current { color: #fff; }
</style>

<div>
    {{-- Fil d'ariane --}}
    <nav class="fi-breadcrumbs lwt-crumbs">
        <ol>
            <li><a href="/properties">Biens Immobiliers</a></li>
            @if($propertyId)
                <li>&rsaquo;</li>
                <li><a href="/properties/{{ $propertyId }}/edit">{{ $propertyName ?? '' }}</a></li>
                <li>&rsaquo;</li>
                <li class="lwt-crumb-current">{{ $currentLabel }}</li>
            @else
                <li>&rsaquo;</li>
                <li class="lwt-crumb-current">{{ $currentLabel }}</li>
            @endif
        </ol>
    </nav>

    {{-- Titre + actions --}}
    <div class="fi-header">
        <h1 class="fi-header-heading">
            {{ $heading }}
        </h1>
        @if(!empty($actions))
            {{-- fi-header-actions-ctn (et non fi-header-actions, qui n'existe pas) : flex, gap 12px, shrink-0 --}}
            <div class="fi-header-actions-ctn">
                @foreach($actions as $action)
                    {{ $action }}
                @endforeach
            </div>
        @endif
    </div>
</div>

@if($propertyId)
    <x-property-tabs :propertyId="$propertyId" :active="$active" />
@elseif(!empty($properties) && !empty($currentUrl))
    {{-- Sélecteur de bien --}}
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:16px 20px;background:var(--fi-body-bg,white);border:1px solid var(--fi-border-color,#e5e7eb);border-radius:12px;">
        <label style="font-weight:600;font-size:14px;color:var(--fi-fg,#374151);">Sélectionner un bien :</label>
        <select
            onchange="if(this.value) window.location.href='{{ $currentUrl }}/' + this.value"
            style="padding:6px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;background:var(--fi-body-bg,white);color:var(--fi-fg,#374151);"
        >
            <option value="">— Choisir —</option>
            @foreach($properties as $prop)
                <option value="{{ $prop->id }}">{{ $prop->name }}</option>
            @endforeach
        </select>
    </div>
@endif
