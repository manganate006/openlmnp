<div style="padding: 8px 12px 12px;">
    <div
        x-data
        style="display: flex; align-items: center; gap: 2px; background: rgba(0,0,0,0.06); border-radius: 8px; padding: 3px;"
    >
        <button
            wire:click="setMode('simple')"
            style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 4px; border-radius: 6px; padding: 5px 8px; font-size: 11px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s;
                {{ $mode === 'simple' ? 'background: white; color: #059669; box-shadow: 0 1px 2px rgba(0,0,0,0.1);' : 'background: transparent; color: #9ca3af;' }}"
            title="L'essentiel"
        >
            <span style="font-size: 12px;">&#9638;</span>
            <span x-show="$store.sidebar.isOpen" x-cloak>Simple</span>
        </button>
        <button
            wire:click="setMode('advanced')"
            style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 4px; border-radius: 6px; padding: 5px 8px; font-size: 11px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s;
                {{ $mode === 'advanced' ? 'background: white; color: #059669; box-shadow: 0 1px 2px rgba(0,0,0,0.1);' : 'background: transparent; color: #9ca3af;' }}"
            title="Tout afficher"
        >
            <span style="font-size: 12px;">&#9776;</span>
            <span x-show="$store.sidebar.isOpen" x-cloak>Avanc&eacute;</span>
        </button>
        <button
            wire:click="setMode('guided')"
            style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 4px; border-radius: 6px; padding: 5px 8px; font-size: 11px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s;
                {{ $mode === 'guided' ? 'background: white; color: #059669; box-shadow: 0 1px 2px rgba(0,0,0,0.1);' : 'background: transparent; color: #9ca3af;' }}"
            title="Par &eacute;tapes"
        >
            <span style="font-size: 12px;">&#10148;</span>
            <span x-show="$store.sidebar.isOpen" x-cloak>Guid&eacute;</span>
        </button>
    </div>
</div>
