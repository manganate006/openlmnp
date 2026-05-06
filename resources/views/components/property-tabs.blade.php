@props(['propertyId', 'active' => 'general'])

@php
    $tabs = [
        ['key' => 'general',    'label' => 'Général',     'icon' => 'heroicon-o-home-modern',         'url' => "/properties/{$propertyId}/edit"],
        ['key' => 'works',      'label' => 'Travaux',     'icon' => 'heroicon-o-wrench-screwdriver',  'url' => "/property-works/{$propertyId}"],
        ['key' => 'furniture',  'label' => 'Mobilier',    'icon' => 'heroicon-o-shopping-bag',        'url' => "/furniture/{$propertyId}"],
        ['key' => 'components', 'label' => 'Composants',  'icon' => 'heroicon-o-cube',                'url' => "/depreciation-editor/{$propertyId}"],
    ];
@endphp

<style>
    .pt-tabs { display: flex; gap: 0; margin-bottom: 20px; background: var(--fi-bg-muted, #f3f4f6); border-radius: 10px; padding: 4px; width: fit-content; }
    .pt-tab { display: inline-flex; align-items: center; gap: 6px; padding: 8px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; background: transparent; color: var(--fi-fg-muted, #6b7280); transition: all 0.2s; }
    .pt-tab:hover { color: var(--fi-fg, #374151); }
    .pt-tab-active { background: var(--fi-body-bg, white); color: #10b981; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
    .pt-tab svg { width: 16px; height: 16px; }
    @media (max-width: 768px) { .pt-tabs { width: 100%; flex-wrap: wrap; } .pt-tab { flex: 1; text-align: center; justify-content: center; padding: 8px 12px; font-size: 12px; } }
</style>

<nav class="pt-tabs">
    @foreach($tabs as $tab)
        <a href="{{ $tab['url'] }}" class="pt-tab {{ $active === $tab['key'] ? 'pt-tab-active' : '' }}">
            <x-filament::icon :icon="$tab['icon']" />
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
