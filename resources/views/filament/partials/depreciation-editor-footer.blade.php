{{--
    Pied de l'éditeur d'amortissements — AVERTISSEMENT + ACTIONS.

    ⚠️ Il n'a PAS de carte à lui, et c'est le point. « Enregistrer mon plan » flottait
    seul dans un `de-card` dédié, détaché de ce sur quoi il agit. Il ferme désormais la
    carte du mode courant : la carte du tableau en mode « Montants », la carte du bas de
    colonne en mode « Ventilation ». D'où un partial inclus DEUX FOIS plutôt qu'un bloc
    unique — un bloc unique ne peut fermer qu'une seule des deux cartes.

    ⚠️ Et il porte l'avertissement de suppression, parce que c'est le même défaut :
    `DepreciationService::syncComponents()` est une réconciliation, tout composant absent
    du lot soumis est SUPPRIMÉ. Le remplacement est voulu, son silence ne l'était pas.
    Le bandeau « reste à ventiler » parle de ventilation, jamais de suppression.

    Variables héritées de la vue hôte : $showReset, $saveLabel.
--}}
<div class="de-doomed" x-show="pendingDeletions().length > 0" x-cloak role="alert">
    <b x-text="doomedHeadline()"></b>
    <span x-text="pendingDeletions().join(', ')"></span>
    <span class="de-doomed-why">
        Leur base, leur durée et leur cumul repris seront perdus. C'est voulu&nbsp;: enregistrer
        <strong>remplace</strong> votre plan d'amortissement par les lignes affichées ci-dessus.
        Pour en conserver un, cochez-le et donnez-lui un montant.
    </span>
</div>

<div class="de-editor-foot">
    @if($showReset)
        <button class="de-btn de-btn-secondary de-editor-foot-left" @click="confirmReset()">
            Réinitialiser par défaut
        </button>
    @endif
    <span class="de-dirty-badge" x-show="isDirty" x-cloak>Modifications non enregistrées</span>
    {{-- Plus jamais désactivé : sous-ventiler est légitime, et le serveur refuse la
         sur-ventilation avec un message explicite. --}}
    <button class="de-btn de-btn-primary" @click="save()">
        {{ $saveLabel }}
    </button>
</div>
