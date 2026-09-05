<div class="ctx-help">
    <h3>Exercices fiscaux</h3>
    <p>Un exercice fiscal correspond à une année civile. C'est ici que le résultat fiscal est calculé pour votre déclaration de revenus.</p>

    <h3>Créer un exercice</h3>
    <div class="ctx-step"><span class="ctx-step-num">1</span><span class="ctx-step-text">Vérifiez que toutes les recettes et charges de l'année sont saisies</span></div>
    <div class="ctx-step"><span class="ctx-step-num">2</span><span class="ctx-step-text">Créez l'exercice via l'assistant ou le bouton « Nouveau »</span></div>
    <div class="ctx-step"><span class="ctx-step-num">3</span><span class="ctx-step-text">Lancez le calcul du résultat fiscal</span></div>

    <h3>Résultat fiscal</h3>
    <p>Le calcul : <strong>Recettes - Charges - Amortissements = Résultat</strong></p>
    <p>L'amortissement est plafonné pour ne pas créer de déficit. L'excédent non utilisé est reporté indéfiniment sur les années suivantes.</p>

    <h3>Documents générés</h3>
    <ul>
        <li data-icon="&#x1F4C4;"><strong>Liasse fiscale PDF</strong> &mdash; Formulaires 2031, 2033-A, 2033-B, 2033-C et 2033-D pré-remplis</li>
        <li data-icon="&#x1F4BE;"><strong>FEC</strong> &mdash; Fichier des Écritures Comptables (obligatoire en cas de contrôle)</li>
    </ul>

    <h3>Exercice de reprise : les soldes d'ouverture</h3>
    <p>Vous arrivez d'un expert-comptable ou d'un autre logiciel ? Le bouton <strong>« Reprendre un dossier »</strong>, en haut de cette liste, saisit d'un coup ce que votre dernière liasse vous laisse en report. On ne renseigne pas ces montants exercice par exercice.</p>
    <ul>
        <li data-icon="&#x1F522;"><strong>Amortissements différés</strong> &mdash; 2033-D case 870. La part d'amortissement que vous n'avez pas pu déduire faute de bénéfice : elle se reporte <strong>sans limite de durée</strong>.</li>
        <li data-icon="&#x1F4C9;"><strong>Déficits reportables</strong> &mdash; 2033-D case 984, à ventiler <strong>par année d'origine</strong> : chacun ne s'impute que sur vos bénéfices de location meublée des <strong>dix</strong> années suivantes. L'assistant affiche, pour chaque ligne, sa dernière année d'imputation.</li>
        <li data-icon="&#x1F5C3;"><strong>Amortissements cumulés</strong> &mdash; 2033-A case 030. Donnée de contrôle : elle sert à vérifier que la reprise tombe juste, elle n'entre dans aucun calcul de résultat.</li>
    </ul>

    <h3>La colonne « Reprise »</h3>
    <p>Un badge <strong>Reprise</strong> marque l'exercice qui porte des soldes d'ouverture. Survolez-le : l'infobulle rappelle les montants repris et leur provenance (liasse fiscale, saisie manuelle&hellip;).</p>

    <div class="ctx-warning">
        <strong>Un exercice N-1 vide écrase votre report.</strong> Si l'année qui précède votre reprise figure dans cette liste sans rien contenir (brouillon créé puis jamais alimenté), c'est elle qui devrait fournir le report &mdash; et elle vaut 0 €. Vos soldes d'ouverture sont conservés, mais le badge <strong>Reprise</strong> passe au rouge et l'infobulle vous le dit : supprimez ou complétez cet exercice vide pour que la chaîne soit juste.
    </div>

    <div class="ctx-tip" style="margin-top:12px;">
        <strong>Après avoir corrigé un montant ancien :</strong> les exercices déjà enregistrés gardent leurs totaux figés. Le bouton <strong>« Recalculer la chaîne »</strong> les rejoue dans l'ordre chronologique pour que les reports redeviennent cohérents.
    </div>

    <div class="ctx-tip">
        <strong>Astuce :</strong> Comparez avec le micro-BIC dans le simulateur pour vérifier que le régime réel reste avantageux.
    </div>
</div>
