@php
    $tabLabels = ['general' => 'Général', 'works' => 'Travaux', 'furniture' => 'Mobilier', 'components' => 'Composants'];
    $currentLabel = $tabLabels[$active] ?? $heading;
    $properties = $properties ?? null;
    $currentUrl = $currentUrl ?? null;
@endphp

<div>
    {{-- Fil d'ariane --}}
    <nav class="fi-breadcrumbs mb-2">
        <ol class="fi-breadcrumbs-list flex flex-wrap items-center gap-x-2 text-sm text-gray-500 dark:text-gray-400">
            <li><a href="/properties" class="hover:underline">Biens Immobiliers</a></li>
            @if($propertyId)
                <li class="fi-breadcrumbs-separator">&rsaquo;</li>
                <li><a href="/properties/{{ $propertyId }}/edit" class="hover:underline">{{ $propertyName ?? '' }}</a></li>
                <li class="fi-breadcrumbs-separator">&rsaquo;</li>
                <li class="font-medium text-gray-950 dark:text-white">{{ $currentLabel }}</li>
            @else
                <li class="fi-breadcrumbs-separator">&rsaquo;</li>
                <li class="font-medium text-gray-950 dark:text-white">{{ $currentLabel }}</li>
            @endif
        </ol>
    </nav>

    {{-- Titre + actions --}}
    <div class="fi-header flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h1 class="fi-header-heading text-2xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-3xl">
            {{ $heading }}
        </h1>
        @if(!empty($actions))
            <div class="fi-header-actions flex shrink-0 items-center gap-3">
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
