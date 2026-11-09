<div class="ctx-help">
    <h3>Importer un justificatif</h3>
    <p>Déposez une facture, un avis d'imposition, une attestation d'assurance, un tableau d'amortissement ou un relevé de compte : l'analyse en <strong>propose</strong> l'écriture, vous la <strong>relisez</strong>, vous la <strong>corrigez</strong> si besoin, puis vous la validez. <strong>Rien n'est jamais enregistré sans votre relecture.</strong></p>

    <h3>Documents acceptés</h3>
    <ul>
        <li data-icon="&#x1F4C4;"><strong>PDF, JPEG, PNG ou WebP</strong> — 10 Mo maximum, une pièce à la fois</li>
        <li data-icon="&#x1F4F1;"><strong>Photo au téléphone</strong> — convient très bien ; cadrez le document entier et évitez les reflets</li>
        <li data-icon="&#x1F4F7;"><strong>HEIC</strong> (photos iPhone) — accepté : la photo est convertie en JPEG dans votre navigateur avant l'envoi, que vous la choisissiez sur l'iPhone ou depuis un ordinateur</li>
        <li data-icon="&#x1F5A5;"><strong>Serveur local</strong> — un PDF scanné, sans couche texte, se dépose en image</li>
    </ul>

    <h3>Les étapes</h3>
    <div class="ctx-step"><span class="ctx-step-num">1</span><span class="ctx-step-text">Choisissez le <strong>bien concerné</strong></span></div>
    <div class="ctx-step"><span class="ctx-step-num">2</span><span class="ctx-step-text">Laissez le type sur <strong>Détection automatique</strong>, sauf si vous savez déjà de quoi il s'agit</span></div>
    <div class="ctx-step"><span class="ctx-step-num">3</span><span class="ctx-step-text">Déposez le document puis cliquez sur <strong>Analyser</strong> (quelques secondes)</span></div>
    <div class="ctx-step"><span class="ctx-step-num">4</span><span class="ctx-step-text"><strong>Relisez</strong> chaque champ proposé, corrigez ce qui doit l'être</span></div>
    <div class="ctx-step"><span class="ctx-step-num">5</span><span class="ctx-step-text">Cliquez sur <strong>Créer</strong> — ou <strong>Créer et analyser un autre</strong> pour enchaîner. Le document est rattaché à l'écriture comme pièce justificative</span></div>

    <h3>Lire l'écran de relecture</h3>
    <ul>
        <li data-icon="&#x2705;"><strong>Confiance</strong> — le pourcentage global indique à quel point le modèle est sûr de sa lecture. Chaque champ porte « Lu avec certitude » ou « À vérifier » ; un champ à vérifier mérite un coup d'œil sur le document affiché à droite</li>
        <li data-icon="&#x1F50D;"><strong>Réanalyser avec le modèle renforcé</strong> — relance l'analyse avec le modèle de repli quand la première lecture est douteuse (compte pour un document supplémentaire)</li>
        <li data-icon="&#x26A0;"><strong>Doublon possible</strong> — une écriture du même montant existe déjà à trois jours près : vérifiez avant de créer</li>
        <li data-icon="&#x1F3E0;"><strong>100 % dédié au bien loué</strong> — cet interrupteur apparaît sur les <strong>trois</strong> familles : charges, mobilier et travaux. Coché, la totalité du montant est retenue ; décoché, la <strong>quote-part de surface</strong> s'applique (part louée / surface totale).
            <br>Le modèle ne peut pas le deviner d'une facture : il propose « dédié » dès que le bien n'est pas votre résidence principale, et c'est à vous de trancher. Sur du mobilier et des travaux, c'est l'<strong>assiette amortissable</strong> qui est en jeu — un bien mixte coché à tort voit sa dotation multipliée par l'inverse de la quote-part, et la déduction devient fausse pour toute la durée de l'amortissement.</li>
        <li data-icon="&#x1F4C4;"><strong>Sur un relevé bancaire</strong>, la colonne « Dédiée » permet de trancher <strong>ligne par ligne</strong> : une commission de plateforme est dédiée, une facture d'électricité ne l'est généralement pas.</li>
        <li data-icon="&#x1F504;"><strong>Changer le type de document</strong> conserve ce que vous avez corrigé à la main — montant rectifié, date, interrupteur basculé. Seuls les champs que le nouveau type ne connaît pas disparaissent.</li>
    </ul>

    <h3>Ce que l'analyse sait reconnaître</h3>
    <ul>
        <li data-icon="&#x1F9FE;"><strong>Factures de charges</strong> — date, montant TTC, TVA, catégorie et récurrence déduites du document</li>
        <li data-icon="&#x1FA91;"><strong>Mobilier</strong> — une ligne amortissable par article, durée déduite de la nature du bien</li>
        <li data-icon="&#x1F528;"><strong>Travaux</strong> — 5, 10 ou 15 ans selon leur nature</li>
        <li data-icon="&#x1F4B6;"><strong>Relevés annuels de plateforme</strong> &mdash; le rapport de revenus d'Airbnb, Booking ou Abritel crée <strong>une recette par mois loué</strong>, au montant <strong>brut</strong>, la commission de la plateforme étant enregistrée à part. Les mois sans location sont ignorés.
            <br>⚠️ <strong>N'importez pas à la fois le relevé annuel et le CSV de la plateforme</strong> : ce sont les mêmes euros sous deux mailles différentes, et votre année serait comptée deux fois. L'écran vous prévient et décoche tout si des recettes existent déjà sur l'exercice &mdash; mais l'inverse n'est pas détecté : un import CSV fait <em>après</em> un relevé annuel passerait sans alerte.
            <br>La <strong>taxe de séjour</strong> collectée par la plateforme n'est répartie sur aucun mois : elle n'est pas comprise dans vos revenus bruts, et aucune ligne n'est créée pour elle.</li>
        <li data-icon="&#x1F3DB;"><strong>Taxe foncière et assurance</strong> — année, montant, échéance</li>
        <li data-icon="&#x1F3E6;"><strong>Tableau d'amortissement</strong> — vos échéances réelles remplacent le tableau théorique de l'emprunt et sont protégées de tout recalcul automatique</li>
        <li data-icon="&#x1F4CB;"><strong>Relevé bancaire</strong> — les opérations sont proposées une à une, déjà classées ; celles déjà saisies sont décochées. L'IBAN n'est jamais conservé en entier</li>
    </ul>

    <h3>Quota et coût</h3>
    <p>Le compteur « x / N documents analysés ce mois-ci » en bas de page indique votre plafond mensuel. <strong>Un document analysé compte pour une unité</strong>, même si l'analyse a nécessité plusieurs appels ; une analyse annulée après relecture a tout de même été comptée. Dans les formulaires de charge, de mobilier et de travaux, le bouton <strong>Pré-remplir depuis ce document</strong> offre la même analyse sans quitter la saisie.</p>

    <h3>Si vous utilisez le coffre-fort</h3>
    <p>Les deux fonctionnalités cohabitent, mais elles ne protègent pas la même chose, et la nuance compte.</p>
    <ul>
        <li><strong>L'analyse envoie le document en clair.</strong> Le coffre chiffre ce qui est <em>enregistré</em> ; il ne protège pas ce que vous choisissez de faire analyser. C'est la seule façon qu'un service d'IA ait de lire une facture. Si un document ne doit sortir sous aucune forme, saisissez-le à la main.</li>
        <li><strong>Le fichier conservé, lui, est scellé.</strong> Une fois l'écriture créée, le justificatif rejoint vos documents <em>chiffré</em>, comme si vous l'aviez déposé vous-même.</li>
        <li><strong>Un justificatif déjà scellé ne peut plus être analysé.</strong> Le serveur ne sait plus le lire &mdash; c'est exactement ce que le coffre garantit, pas une panne. Pour le faire analyser, redéposez le fichier d'origine depuis votre ordinateur.</li>
        <li><strong>Déposer fonctionne coffre verrouillé.</strong> Vous n'avez pas besoin de saisir votre phrase de passe pour importer un document : chiffrer ne demande que la moitié publique de votre clé.</li>
    </ul>

    <div class="ctx-warning">
        <strong>L'IA peut se tromper.</strong> Vérifiez toujours le montant, la date et la catégorie avant de créer l'écriture : c'est vous qui validez, et c'est votre déclaration.
    </div>

    <div class="ctx-tip">
        <strong>Confidentialité :</strong> sont transmis au service d'IA le document déposé, le nom et l'adresse du bien — jamais vos autres données. Chez OpenRouter, chaque requête porte une consigne de ne rien conserver ni utiliser pour l'entraînement : c'est une préférence transmise à un intermédiaire, pas un engagement que nous puissions tenir à sa place, et les autres fournisseurs ne la reçoivent pas. Ce que nous maîtrisons, en revanche : l'application ne journalise que des compteurs (date, modèle, jetons, durée, coût), jamais le contenu. Et en auto-hébergement, un modèle installé chez vous fait que rien ne quitte votre réseau — c'est le seul cas qui ne dépende de personne d'autre.
    </div>
</div>
