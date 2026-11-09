{{-- Marque du panel, alignée sur la vitrine : même maison blanche sur carré
     émeraude que `vitrine/public/favicon.svg`, et le même `#059669` — qui est
     aussi l'émeraude 600 de `Color::Emerald`, la couleur primaire du panel.

     Le fichier est dupliqué plutôt que partagé : les deux dépôts sont distincts,
     et l'app est publique. Un auto-hébergé doit pouvoir la remplacer sans rien
     savoir de la vitrine. --}}
<span class="olmnp-brand">
    <img src="{{ asset('images/logo-openlmnp.svg') }}" alt="" width="28" height="28">
    <span class="olmnp-brand-nom">OpenLMNP</span>
</span>

<style>
    .olmnp-brand { display: inline-flex; align-items: center; gap: 10px; }
    .olmnp-brand img { width: 28px; height: 28px; border-radius: 7px; display: block; }
    .olmnp-brand-nom { font-size: 18px; font-weight: 700; letter-spacing: -0.01em;
        color: var(--olmnp-fg-strong); }
</style>
