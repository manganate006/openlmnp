<div class="ctx-help">
    <h3>Votre version et les nouveautés</h3>
    <p>
        Cet écran répond à trois questions : quelle version d'OpenLMNP vous utilisez, s'il en
        existe une plus récente, et ce qui a changé dans les dernières livraisons.
    </p>

    <h3>Ce que vous voyez</h3>
    <ul>
        <li data-icon="&#x2728;"><strong>Carte « Nouveauté »</strong> &mdash; met en avant une fonction récente, tant que vous ne l'avez pas fermée. Fermer la carte la mémorise pour cette version : elle réapparaît seulement à la prochaine release qui a quelque chose à annoncer. Sur une instance où les dossiers liés sont désactivés, la carte adapte son contenu et son bouton.</li>
        <li data-icon="&#x1F4CC;"><strong>Version installée</strong> &mdash; celle qui tourne en ce moment, avec sa date de publication.</li>
        <li data-icon="&#x1F195;"><strong>Pastille d'état</strong> &mdash; « Version à jour », ou « v… disponible » si une version plus récente a été publiée. Sur une instance gérée pour vous (offre en ligne, image Docker officielle), cette pastille ne s'affiche pas : la mise à jour est faite par l'exploitant, vous n'avez rien à lancer. Les nouveautés ci-dessous, elles, restent visibles.</li>
        <li data-icon="&#x1F4DD;"><strong>Nouveautés</strong> &mdash; les notes des cinq dernières versions. Celle que vous utilisez porte la mention « votre version » ; les suivantes, « plus récente » : ce sont les changements qui vous manquent.</li>
    </ul>

    <h3>D'où viennent ces informations</h3>
    <p>
        La version installée est lue dans l'application elle-même, sans rien demander à
        personne. Les notes de version, elles, viennent des <em>releases</em> publiées sur le
        dépôt GitHub du projet : c'est le seul échange avec l'extérieur de cette page, et il ne
        transmet aucune de vos données. Le résultat est conservé une heure pour éviter d'y
        revenir à chaque affichage.
    </p>

    <div class="ctx-tip">
        Sur une instance sans accès à Internet, ce relevé peut être coupé
        (<strong>UPDATE_CHECK_ENABLED=false</strong>). Votre version reste affichée ; seules les
        notes des versions publiées deviennent indisponibles.
    </div>

    <h3>Comment mettre à jour ?</h3>
    <p>
        Ça ne se fait pas depuis cet écran. Si vous utilisez l'offre en ligne, les mises à jour
        sont appliquées pour vous : il n'y a rien à faire. Si vous hébergez OpenLMNP vous-même,
        l'installation se pilote depuis la page <strong>Mises à jour</strong>, réservée aux
        comptes administrateurs.
    </p>
</div>
