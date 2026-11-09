<div class="ctx-help">
    <h3>À quoi sert le coffre-fort</h3>
    <p>Il chiffre le <strong>contenu</strong> des fichiers que vous déposez &mdash; justificatifs comptables et photos de biens &mdash; avec une clé dérivée d'une phrase de passe que <strong>vous seul</strong> connaissez. Une copie du disque, d'une sauvegarde ou de la réplique de secours ne contient alors rien de lisible.</p>
    <p>La fonctionnalité est <strong>optionnelle et réversible</strong> : tant que vous ne l'activez pas, rien ne change.</p>

    <h3>Activer</h3>
    <div class="ctx-step"><span class="ctx-step-num">1</span><span class="ctx-step-text">Choisissez une phrase de passe d'au moins <strong>12 caractères</strong>, <strong>différente de votre mot de passe de connexion</strong>.</span></div>
    <div class="ctx-step"><span class="ctx-step-num">2</span><span class="ctx-step-text">Recopiez « JE COMPRENDS » : c'est la contrepartie, et elle est définitive.</span></div>
    <div class="ctx-step"><span class="ctx-step-num">3</span><span class="ctx-step-text">Vos fichiers déjà déposés sont chiffrés par lots. Laissez la page ouverte jusqu'à la fin ; une opération interrompue se reprend là où elle s'était arrêtée.</span></div>

    <div class="ctx-warning">
        <strong>Perdre la phrase de passe, c'est perdre les fichiers.</strong> Elle n'est enregistrée nulle part, ni en clair ni sous forme dérivée : ni nous, ni l'hébergeur de cette instance, ni personne d'autre ne peut restituer vos justificatifs. Il n'existe aucune procédure de récupération. Notez-la ailleurs, dans un gestionnaire de mots de passe par exemple.
    </div>

    <div class="ctx-warning">
        <strong>Le mot de passe de connexion et la phrase de passe sont deux choses distinctes.</strong> Changer votre mot de passe de connexion n'a <strong>aucun effet</strong> sur le coffre-fort, et une réinitialisation de mot de passe (le lien « mot de passe oublié ») ne récupère <strong>rien</strong> : elle vous rend l'accès à votre compte, pas à vos fichiers.
    </div>

    <h3>Déverrouiller, verrouiller</h3>
    <p>Votre clé ne vit que le temps de votre visite. Vous devrez ressaisir votre phrase de passe :</p>
    <ul>
        <li data-icon="&#x1F513;"><strong>À chaque nouvelle session</strong> &mdash; la clé disparaît à la fermeture du navigateur.</li>
        <li data-icon="&#x23F3;"><strong>Après une longue inactivité</strong> &mdash; 2 heures par défaut, comme votre session.</li>
        <li data-icon="&#x1F512;"><strong>Quand vous le décidez</strong> &mdash; le bouton <strong>« Verrouiller »</strong> referme le coffre immédiatement, sans vous déconnecter.</li>
    </ul>

    <h3>Coffre verrouill&eacute;&nbsp;: ce que vous pouvez quand m&ecirc;me faire</h3>
    <p>Verrouill&eacute; ne veut plus dire bloqu&eacute;. <strong>D&eacute;poser un justificatif fonctionne</strong> sans votre phrase de passe&nbsp;: le chiffrement n'a besoin que de la moiti&eacute; publique de votre cl&eacute;. Seule la lecture demande la phrase.</p>
    <p>Un justificatif d&eacute;pos&eacute; coffre verrouill&eacute; se d&eacute;chiffre <strong>dans votre navigateur</strong>, jamais sur le serveur&nbsp;: ouvrez-le depuis sa fiche, par l'ic&ocirc;ne d'ouverture ou de t&eacute;l&eacute;chargement du document. Son lien ouvert directement (clic du milieu, &laquo;&nbsp;ouvrir dans un nouvel onglet&nbsp;&raquo;, adresse recopi&eacute;e) affiche une page qui explique cette marche &agrave; suivre.</p>
    <ul>
        <li data-icon="&#x1F4CE;"><strong>Joindre une pi&egrave;ce</strong> &mdash; sur une charge, des travaux, du mobilier&nbsp;: rien &agrave; saisir. L'application chiffre un document qu'elle ne peut pas relire.</li>
        <li data-icon="&#x1F916;"><strong>Depuis un assistant</strong> &mdash; l'API accepte aussi les d&eacute;p&ocirc;ts, pour la m&ecirc;me raison.</li>
        <li data-icon="&#x1F510;"><strong>Ouvrir une pi&egrave;ce ou une photo</strong> &mdash; l&agrave;, une fen&ecirc;tre demande votre phrase de passe, une seule fois pour la visite.</li>
        <li data-icon="&#x1F4E6;"><strong>Export group&eacute;</strong> &mdash; m&ecirc;me chose&nbsp;: l'archive est assembl&eacute;e pendant que vous regardez, un compteur indique o&ugrave; elle en est.</li>
    </ul>
    <div class="ctx-tip">
        <strong>Le d&eacute;chiffrement se fait dans votre navigateur.</strong> Votre phrase de passe ne part pas sur le serveur, et lui ne voit jamais vos fichiers en clair. C'est aussi pourquoi l'export peut prendre quelques secondes&nbsp;: c'est votre machine qui travaille.
    </div>

    <h3>Changer de phrase de passe</h3>
    <p>Instantané, quel que soit le nombre de justificatifs : <strong>aucun fichier n'est réécrit</strong>, seule la clé est rescellée. Il faut connaître la phrase actuelle &mdash; sans elle, l'opération est impossible.</p>

    <h3>Dossiers liés</h3>
    <p>Un dossier lié a <strong>son propre coffre-fort</strong>, avec sa propre phrase de passe —
        jamais celle de votre compte principal. Basculer vers un dossier, ou en revenir, ne
        reverrouille pas l'autre : chacun garde sa clé déverrouillée de son côté, tant que sa
        propre fenêtre d'inactivité n'a pas expiré.</p>

    <h3>Désactiver</h3>
    <p>Tous les fichiers sont déchiffrés et redeviennent lisibles par l'hébergeur de cette instance, <strong>à l'octet près</strong>, exactement comme avant l'activation. Votre phrase de passe vous est demandée à ce moment-là : elle sert uniquement à ce déchiffrement et n'est conservée nulle part.</p>

    <h3>Ce que le coffre-fort ne protège pas</h3>
    <ul>
        <li data-icon="&#x1F4C4;"><strong>Le reste de votre comptabilité</strong> &mdash; libellés, montants, dates, adresses des biens : tout cela reste en clair en base.</li>
        <li data-icon="&#x1F3F7;"><strong>Le nom des fichiers</strong> &mdash; construit à partir de la date et du libellé, il en raconte souvent l'essentiel.</li>
        <li data-icon="&#x1F5C4;"><strong>Les documents que l'application produit</strong> &mdash; liasse fiscale, FEC, export ZIP des justificatifs : ils sont fabriqués à partir de données qui restent en clair, l'archive ZIP par nature.</li>
        <li data-icon="&#x26A0;"><strong>Un serveur compromis, mais moins qu'avant.</strong> Votre phrase de passe et vos cl&eacute;s ne quittent plus votre navigateur : qui prendrait le serveur n'y trouverait aucune cl&eacute;, et vos fichiers y resteraient illisibles. En revanche, il pourrait modifier la page que vous recevez pour lui faire renvoyer votre phrase au moment o&ugrave; vous la tapez. Le coffre prot&egrave;ge la donn&eacute;e <strong>au repos</strong> ; il ne remplace pas un serveur sain.</li>
    </ul>

    <h3>Analyse par l'IA</h3>
    <p>Le coffre-fort ne couvre <strong>pas</strong> l'analyse, et c'est le point qu'il faut avoir en tête avant de déposer une pièce sensible pour la faire lire.</p>
    <ul>
        <li data-icon="&#x26A0;"><strong>Un document soumis à l'analyse part en clair</strong> chez le service d'intelligence artificielle configur&eacute; sur l'instance. Le coffre chiffre ce qui est <em>enregistr&eacute;</em>, pas ce que vous choisissez de faire lire par un tiers.</li>
        <li data-icon="&#x1F512;"><strong>Le fichier conserv&eacute; est scell&eacute;</strong> comme les autres, une fois l'&eacute;criture cr&eacute;&eacute;e.</li>
        <li data-icon="&#x1F6AB;"><strong>Un justificatif d&eacute;j&agrave; scell&eacute; ne peut plus &ecirc;tre analys&eacute;</strong> : le serveur ne sait plus le lire. C'est la garantie qui fonctionne, pas un d&eacute;faut. Redéposez le fichier d'origine si vous voulez le faire analyser.</li>
    </ul>

    <h3>API MCP</h3>
    <p><code>attach_document</code> <strong>fonctionne &agrave; nouveau</strong> coffre activ&eacute; : d&eacute;poser ne demande que la moiti&eacute; publique de votre cl&eacute;, donc un assistant peut joindre un justificatif qu'il ne pourra jamais relire. <code>export_documents</code> reste refus&eacute;, et c'est d&eacute;lib&eacute;r&eacute; : il produirait une archive en clair sur le serveur, ce que le coffre existe pr&eacute;cis&eacute;ment pour &eacute;viter. <code>delete_document</code> reste disponible, il ne lit jamais le contenu d'un fichier.</p>

    <div class="ctx-tip">
        <strong>Comptes de démonstration :</strong> le coffre-fort n'y est pas proposé. Un bac à sable est éphémère et ses identifiants sont publics : y saisir une phrase de passe donnerait une fausse impression de confidentialité.
    </div>

    <div class="ctx-tip">
        <strong>L'entrée « Coffre-fort » a disparu du menu ?</strong> Elle n'est pas affichée en mode <strong>Simple</strong>. Basculez sur <strong>Avancé</strong> ou <strong>Guidé</strong> avec les trois boutons situés au bas de la barre latérale.
    </div>
</div>
