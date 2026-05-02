<div class="ctx-help">
    <h3>Gestion des mises à jour</h3>
    <p>Mettez à jour OpenLMNP directement depuis cette interface, sans accès SSH.</p>

    <h3>Configuration GitHub</h3>
    <ul>
        <li data-icon="&#x1F511;"><strong>Token GitHub</strong> &mdash; Un token d'accès personnel pour les dépôts privés. Sans token, seuls les dépôts publics sont accessibles.</li>
        <li data-icon="&#x1F4E6;"><strong>Dépôt</strong> &mdash; Au format <strong>utilisateur/repo</strong> (ex : manganate006/openlmnp).</li>
    </ul>

    <h3>Processus de mise à jour</h3>
    <div class="ctx-step"><span class="ctx-step-num">1</span><span class="ctx-step-text"><strong>Vérifier</strong> &mdash; Compare votre version avec la dernière disponible sur GitHub</span></div>
    <div class="ctx-step"><span class="ctx-step-num">2</span><span class="ctx-step-text"><strong>Appliquer</strong> &mdash; Télécharge, sauvegarde l'existant, et déploie la nouvelle version</span></div>
    <div class="ctx-step"><span class="ctx-step-num">3</span><span class="ctx-step-text"><strong>Post-déploiement</strong> &mdash; composer install, npm build, migrations, cache clear</span></div>

    <div class="ctx-warning">
        <strong>Important :</strong> Un backup automatique est créé avant chaque mise à jour. Vos données (base SQLite, fichiers uploadés) ne sont jamais écrasées.
    </div>
</div>
