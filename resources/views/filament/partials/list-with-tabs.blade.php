<div class="fi-header flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <h1 class="fi-header-heading text-2xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-3xl">
            {{ $heading }}
        </h1>
        @if($subheading)
            <p class="fi-header-subheading mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $subheading }}</p>
        @endif
    </div>
    @if(!empty($actions))
        <div class="fi-header-actions flex shrink-0 items-center gap-3">
            @foreach($actions as $action)
                {{ $action }}
            @endforeach
        </div>
    @endif
</div>

<x-property-tabs :propertyId="$propertyId" :active="$active" />
