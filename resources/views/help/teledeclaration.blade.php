<div class="ctx-help">
    <h3>Télédéclaration</h3>
    <p>Guide étape par étape pour reporter vos résultats LMNP sur votre déclaration de revenus en ligne.</p>

    <h3>Formulaire 2042-C-PRO</h3>
    <ul>
        <li data-icon="&#x2705;"><strong>Case 5NA</strong> &mdash; Si votre résultat est un <strong>bénéfice</strong> (5NK sans adhésion à un organisme de gestion agréé)</li>
        <li data-icon="&#x274C;"><strong>Case 5NY</strong> &mdash; Si votre résultat est un <strong>déficit</strong> (5NZ sans organisme de gestion agréé)</li>
    </ul>
    <p>L'écran vous indique la case retenue et le montant à y porter : recopiez-les tels quels.</p>

    <h3>Étapes sur impots.gouv.fr</h3>
    <div class="ctx-step"><span class="ctx-step-num">1</span><span class="ctx-step-text">Connectez-vous sur <strong>impots.gouv.fr</strong></span></div>
    <div class="ctx-step"><span class="ctx-step-num">2</span><span class="ctx-step-text">Accédez à votre déclaration de revenus</span></div>
    <div class="ctx-step"><span class="ctx-step-num">3</span><span class="ctx-step-text">Cochez la case <strong>« Revenus des locations meublées non professionnelles »</strong></span></div>
    <div class="ctx-step"><span class="ctx-step-num">4</span><span class="ctx-step-text">Reportez le montant du résultat dans la case appropriée</span></div>
    <div class="ctx-step"><span class="ctx-step-num">5</span><span class="ctx-step-text">Validez et conservez l'accusé de réception</span></div>

    <h3>Contrôles de cohérence</h3>
    <p>En bas de l'écran, deux vérifications rapprochent des montants que les formulaires calculent séparément. Elles ne remplacent pas votre relecture, mais elles attrapent les incohérences les plus courantes avant que vous ne saisissiez la liasse.</p>
    <ul>
        <li data-icon="&#x1F9EE;"><strong>Ligne 572 = ligne 254</strong> &mdash; La dotation aux amortissements de l'exercice doit être la même dans le 2033-C et dans le compte de résultat.</li>
        <li data-icon="&#x1F3E0;"><strong>Case 044 = ligne 490</strong> &mdash; Le total des immobilisations brutes doit être le même au bilan et dans le tableau des immobilisations.</li>
    </ul>
    <p>Un contrôle <strong>orange</strong> n'est pas une erreur : il signale qu'une part de votre base amortissable n'est rattachée à aucun composant. C'est autorisé &mdash; mais cette part <strong>ne s'amortira pas</strong>. Si ce n'est pas voulu, complétez la ventilation dans l'éditeur d'amortissements.</p>
    <p>Un contrôle <strong>rouge</strong> demande une correction avant de déclarer : vos composants dépassent la base amortissable, en général parce que la valeur du bien ou la part du terrain ont été modifiées après la ventilation.</p>

    <h3>D'où vient chaque montant : le détail des immobilisations</h3>
    <p>Le tableau 2033-C agrège : une ligne « Constructions » de 60 000 € ne dit pas ce qu'elle contient. Le bloc <strong>« Détail des immobilisations »</strong>, dépliable en bas de cet écran et repris en annexe du PDF, donne une ligne par actif &mdash; son intitulé, son origine (composant du bien, travaux, mobilier, frais d'acquisition), la ligne du 2033-C où il atterrit, sa valeur brute, sa dotation et son cumul.</p>
    <p>Rien à recopier : ce tableau n'est pas un formulaire Cerfa, il sert à retrouver la provenance d'un chiffre qui vous surprend. Une précision utile pour le lire : les <strong>composants du bien ventilent</strong> sa valeur, ils ne s'y ajoutent pas &mdash; la colonne « Valeur brute » ne se totalise donc pas, et c'est voulu.</p>
    <p>Si l'explication ne suffit pas, le bouton <strong>« Rapport de diagnostic »</strong> en haut de l'écran rassemble en un texte tout ce qui produit votre liasse : valeurs saisies, base amortissable, composants, travaux, mobilier, lignes obtenues et écarts. Il ne part nulle part tout seul, et ne contient ni votre nom, ni votre adresse, ni votre commune &mdash; joignez-le à votre message pour qu'on puisse vous répondre sans vous faire tout ressaisir.</p>

    <h3>Tableau 2033-D : deux reports à ne pas confondre</h3>
    <p>Le 2033-D suit <strong>deux stocks distincts</strong>, que l'administration fait d'ailleurs suivre par deux états séparés. Les mélanger, c'est déclarer des reports que vous n'avez pas.</p>
    <ul>
        <li data-icon="&#x1F4C9;"><strong>Cases 982 / 983 / 984 &mdash; vos déficits reportables</strong> : le stock à l'ouverture, la part imputée sur le bénéfice de l'exercice, puis ce qui reste à reporter à la clôture (case 860 : le déficit né de l'exercice lui-même). Un déficit de location meublée non professionnelle ne s'impute que sur vos <strong>bénéfices de même nature</strong>, et seulement pendant <strong>dix ans</strong> (CGI art. 156, I-1&deg; ter). Il ne s'impute jamais sur votre salaire.</li>
        <li data-icon="&#x1F522;"><strong>Case 870 &mdash; vos amortissements différés</strong> : la part d'amortissement que vous n'avez pas pu déduire parce qu'elle aurait creusé un déficit. Elle se reporte <strong>sans limite de durée</strong> et n'est pas un déficit.</li>
    </ul>

    <div class="ctx-warning">
        <strong>Vos anciennes liasses portaient l'amortissement différé dans les cases de déficits.</strong> Jusqu'à la version 1.3.2, les cases 982, 983 et 984 recevaient le montant des amortissements réputés différés : une liasse déjà téléchargée ou déjà transmise affiche donc des déficits qui n'existaient pas. Les valeurs de cet écran sont les valeurs corrigées. Si une déclaration déjà déposée est concernée, régénérez la liasse depuis la page Exercices &mdash; un encart vous le rappelle en haut de cet écran tant que vous avez une liasse d'avant la correction.
    </div>

    <h3>Noter le dépôt : ce qui distingue une liasse déposée d'une liasse imprimée</h3>
    <p>Générer le PDF ou recopier les cases ne dépose rien. Une fois la transmission faite, l'administration rend une preuve : un <strong>certificat de dépôt</strong> pour une saisie en ligne, un <strong>compte rendu de traitement accepté</strong> pour un envoi EDI. Le bouton <strong>« Dépôt »</strong>, en haut de cet écran comme sur chaque ligne de la page Exercices, enregistre sa date et son numéro.</p>
    <p>Rien ne les devine : ni la génération du PDF, ni la clôture de l'exercice ne valent dépôt. Tant que vous n'avez rien saisi, l'exercice reste sans marque &mdash; c'est voulu, une date inventée serait pire qu'une case vide. Une fois notés, un bandeau vert le rappelle sur cet écran, un badge <strong>Déposée</strong> apparaît dans la liste des exercices, et vos assistants connectés en MCP peuvent les lire.</p>
    <p>Le numéro d'accusé est facultatif : si vous ne l'avez pas sous la main, la date seule suffit. Pour annuler l'enregistrement &mdash; dépôt noté par erreur, ou déclaration rectificative &mdash; rouvrez <strong>« Dépôt »</strong> et videz la date : le numéro s'efface avec elle, parce qu'un accusé sans date ne prouve rien.</p>
    <p>Attention à un décalage : les montants de cet écran sont recalculés à chaque affichage. S'ils ont changé depuis votre dépôt (une charge ajoutée, une ventilation corrigée), la liasse déposée ne les porte pas &mdash; c'est une déclaration rectificative qu'il faut, pas un simple recalcul.</p>

    <div class="ctx-warning">
        <strong>Important :</strong> La liasse fiscale (2031, 2033) doit être envoyée séparément au SIE (Service des Impôts des Entreprises) dont vous dépendez.
    </div>
</div>
