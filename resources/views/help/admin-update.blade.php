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

    <h3>Avis de sécurité</h3>
    <p>Quand le projet publie un avis de sécurité qui concerne votre version, un bandeau rouge ou ambre s'affiche en haut de chaque page, pour les administrateurs seulement. Il indique la version qui corrige le problème et, le cas échéant, un lien « Lire l'avis ».</p>
    <p>Le bandeau ne se ferme pas : il disparaît de lui-même une fois OpenLMNP mis à jour vers la version indiquée (avec Docker, récupérez la nouvelle image puis recréez le conteneur).</p>
    <p>Ces avis arrivent avec le check-in quotidien de télémétrie anonyme. Si la télémétrie est désactivée (<strong>TELEMETRY_ENABLED=false</strong>), aucun avis n'est reçu : surveillez alors les publications de sécurité du dépôt GitHub.</p>

    <div class="ctx-warning">
        <strong>Important :</strong> Un backup automatique est créé avant chaque mise à jour. Vos données (base SQLite, fichiers uploadés) ne sont jamais écrasées.
    </div>
</div>
