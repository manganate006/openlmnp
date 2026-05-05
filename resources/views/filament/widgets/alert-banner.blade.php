<x-filament-widgets::widget>
    @php $alerts = $this->getAlerts(); @endphp

    @foreach($alerts as $alert)
        <a href="{{ $alert['url'] }}" style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem 1rem;border-radius:0.75rem;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:0.875rem;font-weight:500;text-decoration:none;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1.25rem;height:1.25rem;flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
            <span>{{ $alert['message'] }}</span>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1rem;height:1rem;flex-shrink:0;margin-left:auto;"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
        </a>
    @endforeach
</x-filament-widgets::widget>
