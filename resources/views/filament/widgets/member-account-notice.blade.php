<x-filament-widgets::widget>
    <div style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem 1rem;border-radius:0.75rem;background:var(--olmnp-info-bg);border:1px solid var(--olmnp-info-border);color:var(--olmnp-info-fg);font-size:0.875rem;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1.25rem;height:1.25rem;flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
        <span>
            Ce compte est un accès gratuit d'indivisaire : il ne permet pas de saisir des biens
            personnels. Rendez-vous sur
            <a href="{{ \App\Filament\Pages\LinkedDossiers::getUrl() }}" style="text-decoration:underline;">Dossiers liés</a>
            pour ouvrir le dossier d'indivision partagé avec vous.
        </span>
    </div>
</x-filament-widgets::widget>
