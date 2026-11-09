<div class="ctx-help">
    <h3>Supervision du serveur MCP</h3>
    <p>Le serveur MCP permet aux assistants IA externes (Claude, etc.) de consulter et de gérer une comptabilité OpenLMNP au nom d'un utilisateur, via des <strong>jetons</strong> que chacun crée dans sa page <em>Jetons MCP</em>. Cette page, réservée aux administrateurs, montre <strong>qui l'utilise, avec quels jetons et quels outils</strong>, pour toute l'instance.</p>

    <h3>Vue d'ensemble</h3>
    <ul>
        <li data-icon="&#x1F465;"><strong>Utilisateurs MCP actifs</strong> — comptes ayant activé l'API MCP dans leur profil</li>
        <li data-icon="&#x1F511;"><strong>Jetons actifs</strong> — clés API en circulation, toutes confondues</li>
        <li data-icon="&#x1F4CA;"><strong>Appels aujourd'hui / total</strong> — volume d'utilisation de l'API</li>
        <li data-icon="&#x1F527;"><strong>Outil le plus utilisé</strong> et <strong>durée moyenne</strong> — pour repérer un usage inhabituel ou un outil lent</li>
        <li data-icon="&#x1F5D3;"><strong>Rétention des logs</strong> — durée de conservation du journal d'audit avant purge</li>
    </ul>

    <h3>Utilisateurs et jetons</h3>
    <ul>
        <li data-icon="&#x1F464;"><strong>Statut MCP</strong> — activé ou désactivé par compte ; le bouton d'action bascule l'accès sans supprimer les jetons. Un compte désactivé voit toutes ses requêtes refusées</li>
        <li data-icon="&#x1F510;"><strong>Jetons</strong> — nom, création, dernière utilisation. <strong>La valeur d'un jeton n'est jamais affichée</strong> : elle n'est montrée qu'une seule fois, à sa création, à l'utilisateur lui-même. Un jeton compromis se révoque depuis la page <em>Jetons MCP</em> de son propriétaire</li>
        <li data-icon="&#x1F4C1;"><strong>Dossier</strong> — un jeton reste toujours celui d'un utilisateur (colonne « Utilisateur »), mais il peut en plus être rattaché à un dossier lié dont celui-ci est membre : la colonne « Dossier » le nomme, « — » pour un jeton personnel. Un dossier devenu inaccessible (membre révoqué, dossier archivé ou fermé) coupe ce jeton dès l'appel suivant, sans jamais retomber sur les données personnelles de son propriétaire</li>
    </ul>

    <h3>Utilisation par outil et journal d'audit</h3>
    <p>Le tableau par outil compte les appels de chaque commande (lecture, création, calculs, exports). Le <strong>journal d'audit</strong> liste les cent derniers appels : date, utilisateur, dossier visé le cas échéant, outil, statut, durée et code d'erreur éventuel — <strong>jamais les données échangées</strong>. Le bouton « Purger les anciens logs » supprime les entrées au-delà de la durée de rétention.</p>

    <div class="ctx-tip">
        <strong>Note :</strong> le mode démonstration n'expose qu'un jeton public en <strong>lecture seule</strong>, sur des données fictives : ses appels apparaissent ici comme ceux de n'importe quel compte, mais aucune écriture n'est possible. Activer ou désactiver le serveur MCP pour toute l'instance se fait par la variable <strong>MCP_ENABLED</strong>.
    </div>
</div>
