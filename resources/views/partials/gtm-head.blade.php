@php
    $gtmId = config('services.gtm.id');
    $gtmSrc = rtrim(config('services.gtm.server_url'), '/') . config('services.gtm.script_path');
    $userType = auth()->check() ? ((auth()->user()->is_demo ?? false) ? 'demo' : 'user') : 'visitor';
    $consent = \App\Support\ConsentState::fromRequest(request());
@endphp
{{-- État initial du dataLayer AVANT le conteneur : user_type permet de distinguer
     les sessions démo des vrais utilisateurs dans GA4 (user property) --}}
<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({ user_type: @js($userType) });
</script>
{{-- Consent Mode v2, AVANT le conteneur.

     ⚠️ C'est l'ORDRE qui protège, pas la présence : des signaux posés après le chargement du
     conteneur laissent les tags se prononcer, et la page paraîtrait conforme à la lecture
     puisque tout y figure.

     ⚠️ Ce partial n'est rendu que si `services.gtm.id` est défini (hook du panel) : une
     instance auto-hébergée ne charge aucun traceur, n'a donc rien à demander, et ne verra
     jamais ni ces signaux ni le bandeau. --}}
<script>
window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
gtag('consent', 'default', {
    ad_storage: 'denied',
    analytics_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    wait_for_update: 500,
});
@if ($consent->decided)
{{-- Choix relu côté SERVEUR : le calculer en JavaScript imposerait un aller-retour pendant
     lequel les tags se seraient déjà prononcés sur le défaut. --}}
gtag('consent', 'update', {
    ad_storage: @js($consent->ads ? 'granted' : 'denied'),
    analytics_storage: @js($consent->analytics ? 'granted' : 'denied'),
    ad_user_data: @js($consent->ads ? 'granted' : 'denied'),
    ad_personalization: @js($consent->ads ? 'granted' : 'denied'),
});
@endif
</script>
{{-- Google Tag Manager (activé uniquement si GTM_CONTAINER_ID est défini) --}}
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
@js($gtmSrc)+'?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer',@js($gtmId));</script>
{{-- Relais des événements applicatifs (dispatch Livewire « analytics ») vers le dataLayer.
     Le payload est passé en tableau positionnel côté PHP (le nom « event » entrerait en
     collision avec le paramètre $event de Livewire dispatch()), d'où detail = [{...}]. --}}
<script>
window.addEventListener('analytics', function (e) {
    var d = e.detail || {};
    if (Array.isArray(d)) { d = d[0] || {}; }
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push(Object.assign({}, d));
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
