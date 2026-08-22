{{--
    Injecté sous la carte des pages d'authentification (render hook SIMPLE_PAGE_END) :
    login, inscription, demande et réinitialisation de mot de passe. Un visiteur doit
    pouvoir lire la politique avant de créer un compte.
    Dans l'app connectée, le lien vit dans le menu de l'avatar (->userMenuItems() de
    AdminPanelProvider) — pas en bandeau de page.

    ⚠️ Aucun utilitaire Tailwind n'existe dans le CSS du panel, et `fi-simple-page-footer`
    n'existe pas non plus : la mise en forme passe par ce <style> scopé.
--}}
<style>
    .olmnp-privacy-footer {
        display: flex;
        justify-content: center;
        padding: 0.75rem 0;
        font-size: 0.75rem;
        line-height: 1rem;
        color: #9ca3af;
    }
    .olmnp-privacy-footer a { color: inherit; text-decoration: none; }
    .olmnp-privacy-footer a:hover { color: var(--primary-600, #059669); }

    .dark .olmnp-privacy-footer { color: #6b7280; }
    .dark .olmnp-privacy-footer a:hover { color: var(--primary-400, #34d399); }
</style>

<div class="olmnp-privacy-footer">
    <a href="{{ route('legal.confidentialite') }}">
        Politique de confidentialité
    </a>
</div>
