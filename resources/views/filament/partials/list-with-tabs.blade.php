<div>
    {{-- Fil d'ariane --}}
    <nav class="fi-breadcrumbs mb-2">
        <ol class="fi-breadcrumbs-list flex flex-wrap items-center gap-x-2 text-sm text-gray-500 dark:text-gray-400">
            <li><a href="/properties" class="hover:underline">Biens Immobiliers</a></li>
            <li class="fi-breadcrumbs-separator">&rsaquo;</li>
            <li class="font-medium text-gray-950 dark:text-white">{{ $propertyName ?? '' }}</li>
        </ol>
    </nav>

    {{-- Titre + actions --}}
    <div class="fi-header flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="fi-header-heading text-2xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-3xl">
                {{ $heading }}
            </h1>
            <p class="fi-header-subheading mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $propertyName ?? '' }}</p>
        </div>
        @if(!empty($actions))
            <div class="fi-header-actions flex shrink-0 items-center gap-3">
                @foreach($actions as $action)
                    {{ $action }}
                @endforeach
            </div>
        @endif
    </div>
</div>

<x-property-tabs :propertyId="$propertyId" :active="$active" />
