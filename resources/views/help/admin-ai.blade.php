<div class="ctx-help">
    <h3>Supervision de l'intelligence artificielle</h3>
    <p>Cette page réunit, pour <strong>tous les comptes de l'instance</strong>, l'usage des deux fonctions d'IA : l'analyse de justificatifs et l'assistant. Elle est réservée aux administrateurs et n'apparaît que si l'IA est activée (<strong>AI_ENABLED=true</strong>).</p>

    <h3>Indicateurs du mois</h3>
    <ul>
        <li data-icon="&#x1F4C4;"><strong>Documents analysés</strong> — analyses abouties (une par justificatif déposé, quel que soit le nombre d'appels nécessaires)</li>
        <li data-icon="&#x1F4AC;"><strong>Messages d'assistant</strong> — questions ayant reçu une réponse</li>
        <li data-icon="&#x1F4B0;"><strong>Coût du mois</strong> — coût réel renvoyé par le fournisseur à chaque appel, additionné au centième de centime près ; vide si votre service ne renvoie pas de coût (modèle local)</li>
        <li data-icon="&#x1F9FE;"><strong>Coût moyen par document</strong> — inclut les appels de suivi automatiques (réparation d'une réponse mal formée, cascade vers le modèle de repli). C'est le vrai prix d'un justificatif</li>
        <li data-icon="&#x1F465;"><strong>Comptes actifs</strong> — comptes ayant fait au moins un appel ce mois-ci</li>
        <li data-icon="&#x26A0;"><strong>Erreurs</strong> — appels échoués côté fournisseur (panne, délai, clé refusée) ; le détail par code figure plus bas</li>
        <li data-icon="&#x1F6AB;"><strong>Refus pour quota atteint</strong> — demandes refusées parce que l'utilisateur avait épuisé son plafond mensuel (documents) ou journalier (assistant). Aucun appel n'a été émis, rien n'a été facturé</li>
        <li data-icon="&#x1F6E1;"><strong>Refus des garde-fous</strong> — réponses de l'assistant remplacées par le message standard parce qu'elles sortaient du périmètre (conseil fiscal, hors sujet, demande d'action)</li>
        <li data-icon="&#x23F1;"><strong>Durée moyenne</strong> — temps de réponse du fournisseur, utile pour repérer un modèle lent</li>
    </ul>

    <h3>Assistant conversationnel</h3>
    <p>Une <strong>question = une unité de quota</strong>, même si l'assistant a dû consulter plusieurs outils pour répondre. La part de refus indique la proportion de questions sorties du périmètre ; les pouces sont les retours laissés par les utilisateurs sur les réponses ; « outils consultés » compte les lectures de données autorisées par l'option <em>Autoriser l'assistant à consulter mes données</em> du profil.</p>
    <p>La section <strong>Conversations signalées</strong>, en bas de page, liste les conversations qu'un utilisateur a choisi de joindre à un pouce vers le bas. Leur texte est masqué avant l'enregistrement (montants, biens, adresses, voyageurs, banques, libellés, nom et e-mail) et ne contient aucune donnée lue par l'assistant&nbsp;; elles sont supprimées après 90 jours. Le budget quotidien de l'instance (<strong>AI_DAILY_BUDGET_USD</strong>, en dollars, tous comptes confondus) met l'IA en pause jusqu'au lendemain une fois atteint, sans concerner les clés personnelles.</p>

    <h3>Configuration effective</h3>
    <p>Ce tableau montre les réglages <strong>effectifs</strong> — pas les variables brutes : le fournisseur, son adresse et les identifiants de modèles peuvent venir des valeurs par défaut du fournisseur plutôt que d'une variable <strong>AI_*</strong>. C'est bien ce que l'application appelle qui est affiché. <strong>La clé n'est jamais affichée</strong>, ni ici ni ailleurs : seule sa provenance est indiquée (clé d'instance ou clé saisie par chaque utilisateur dans son profil).</p>
    <p>Un quota à <strong>0</strong> signifie « illimité » ; un modèle de repli vide signifie « pas de cascade ». Les drapeaux distinguent deux pays qu'il ne faut pas confondre&nbsp;: celui du service qui <strong>achemine</strong> la requ&ecirc;te (ligne &laquo;&nbsp;Fournisseur&nbsp;&raquo;) et celui de l'<strong>&eacute;diteur</strong> de chaque mod&egrave;le. Le <strong>moteur PDF</strong> appartient au service de lecture de fichiers d'OpenRouter : chez un autre fournisseur, il est marqué « sans objet » plutôt que de laisser croire qu'il agit. Pour changer un modèle ou un plafond, modifiez la variable correspondante et redémarrez l'application — aucun déploiement n'est nécessaire.</p>

    <h3>Répartition, erreurs et consommation par compte</h3>
    <ul>
        <li data-icon="&#x1F4CA;"><strong>Par fonction et modèle</strong> — « documents_auto » regroupe les appels de suivi automatiques : ils comptent dans le coût, jamais dans le quota de l'utilisateur</li>
        <li data-icon="&#x1F50D;"><strong>Erreurs du mois</strong> — uniquement des codes (délai dépassé, modèle indisponible, clé refusée…), jamais le message brut du fournisseur</li>
        <li data-icon="&#x1F4C8;"><strong>Consommation par compte</strong> — les vingt comptes les plus actifs, avec leur coût du mois : c'est ici qu'un usage anormal se repère</li>
    </ul>

    <h3>Purger les anciens journaux</h3>
    <p>Le journal est conservé pendant la durée fixée par <strong>AI_USAGE_RETENTION</strong> (90 jours par défaut) et purgé automatiquement chaque nuit. Le bouton « Purger les anciens journaux » force cette purge immédiatement, après confirmation.</p>

    <div class="ctx-tip">
        <strong>Confidentialité :</strong> le journal ne contient que des compteurs, des durées et des coûts — jamais le contenu d'un document, une valeur extraite ni une question posée à l'assistant. Le coût réel d'un justificatif et les plafonds d'usage sont détaillés dans la FAQ (« Combien coûte l'analyse d'un justificatif ? ») et dans le guide d'installation (« Plafonds d'usage »).
    </div>
</div>
