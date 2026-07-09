@php
    $gtmId = config('services.gtm.id');
    $gtmSrc = rtrim(config('services.gtm.server_url'), '/') . config('services.gtm.script_path');
@endphp
{{-- Google Tag Manager (activé uniquement si GTM_CONTAINER_ID est défini) --}}
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
@js($gtmSrc)+'?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer',@js($gtmId));</script>
{{-- Relais des événements applicatifs (dispatch Livewire « analytics ») vers le dataLayer --}}
<script>
window.addEventListener('analytics', function (e) {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push(Object.assign({}, e.detail || {}));
});
</script>
@if (session()->has('analytics'))
{{-- Événements mis en file côté serveur (auth, redirections) --}}
<script>
window.dataLayer = window.dataLayer || [];
@foreach ((array) session('analytics') as $analyticsEvent)
window.dataLayer.push(@js($analyticsEvent));
@endforeach
</script>
@endif
