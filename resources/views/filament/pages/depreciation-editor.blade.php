<x-filament-panels::page>
    @include('filament.partials.depreciation-editor-assets')

    @include('filament.partials.depreciation-editor-core', [
        'data'       => $this->editorData,
        'properties' => $this->properties,
        'propertyId' => $this->propertyId,
    ])
</x-filament-panels::page>
