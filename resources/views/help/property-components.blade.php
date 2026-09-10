<div class="ctx-help">
    <h3>Composants d'amortissement</h3>
    <p>Un bien immobilier est décomposé en plusieurs composants, chacun amorti sur sa propre durée. Cette méthode (amortissement par composants) est obligatoire au régime réel.</p>

    <h3>Composants générés automatiquement</h3>
    <ul>
        <li data-icon="&#x1F3D7;"><strong>Gros &oelig;uvre</strong> &mdash; 50 ans (environ 50 %)</li>
        <li data-icon="&#x1F3E0;"><strong>Toiture</strong> &mdash; 25 ans (environ 10 %)</li>
        <li data-icon="&#x26A1;"><strong>Électricité</strong> &mdash; 25 ans (environ 10 %)</li>
        <li data-icon="&#x1F6BF;"><strong>Plomberie / sanitaire</strong> &mdash; 15 ans (environ 10 %)</li>
        <li data-icon="&#x1F3A8;"><strong>Agencements intérieurs</strong> &mdash; 15 ans (environ 15 %)</li>
        <li data-icon="&#x2600;"><strong>Étanchéité</strong> &mdash; 15 ans (environ 5 %)</li>
    </ul>

    <h3>Maison individuelle : composants supplémentaires</h3>
    <p>Pour une maison, vous pouvez activer des composants spécifiques. Le pourcentage est retiré du gros &oelig;uvre (catégorie résiduelle) :</p>
    <ul>
        <li data-icon="&#x1F3CA;"><strong>Piscine</strong> &mdash; ~7 %, 15 ans. Si le coût réel est connu (facture), préférer le montant réel. Quote-part appliquée si partagée avec usage personnel.</li>
        <li data-icon="&#x2744;"><strong>Climatisation / chauffage</strong> &mdash; ~5 %, 20 ans (extrait des installations techniques)</li>
        <li data-icon="&#x1F373;"><strong>Cuisine équipée</strong> &mdash; ~5 %, 10 ans (extrait des agencements intérieurs)</li>
        <li data-icon="&#x1F6A7;"><strong>VRD (voirie, réseaux)</strong> &mdash; ~3 %, 15 ans (raccordements, allée, portail)</li>
        <li data-icon="&#x1F333;"><strong>Aménagements extérieurs</strong> &mdash; ~5 %, 15 ans (terrasse, clôture, jardin paysager)</li>
    </ul>

    <h3>Appartement : spécificités</h3>
    <p>Pour un appartement en copropriété, les composants par défaut sont généralement suffisants. La toiture, les parties communes et le ravalement sont gérés par le syndic (provisions déductibles en charges).</p>

    <h3>Personnalisation</h3>
    <p>Vous pouvez ajuster les pourcentages et les durées. Quand vous ajoutez un composant optionnel, réduisez principalement le gros &oelig;uvre. Si le coût réel d'un composant est connu (travaux, facture), utilisez-le plutôt que le pourcentage par défaut.</p>

    <h3>Deux modes : Ventilation ou Montants</h3>
    <p>La bascule en haut de l'écran choisit la façon de renseigner le plan.</p>
    <ul>
        <li data-icon="&#x1F39A;"><strong>Ventilation</strong> &mdash; vous répartissez la base amortissable au curseur, en pourcentages. Le mode normal quand vous partez de zéro.</li>
        <li data-icon="&#x1F4B6;"><strong>Montants</strong> &mdash; vous saisissez directement la base de chaque composant, en euros. Le mode à utiliser pour <strong>reprendre une comptabilité déjà tenue ailleurs</strong> : un pourcentage entier ne permet pas de retomber à l'euro près sur les amortissements pratiqués par un cabinet.</li>
    </ul>
    <p>L'écran s'ouvre sur le mode qui correspond à vos données : dès qu'une base a été saisie à la main, c'est <strong>Montants</strong> qui s'affiche.</p>

    <h3>Le tableau des montants, colonne par colonne</h3>
    <ul>
        <li data-icon="&#x1F4D0;"><strong>Ligne 2033-C</strong> &mdash; la rubrique sous laquelle le composant sera imprimé dans la liasse : Constructions (430 / 520), Installations techniques (440 / 530), Agencements et aménagements (450 / 540) ou Autres immobilisations (470 / 560). Elle est proposée d'après le nom, et reste modifiable.</li>
        <li data-icon="&#x1F4B6;"><strong>Base</strong> &mdash; en euros, <strong>quote-part déjà appliquée</strong> : saisissez la part réellement louée, pas la valeur totale du bien.</li>
        <li data-icon="&#x1F4C9;"><strong>Dotation annuelle</strong> &mdash; calculée seule (base &divide; durée), mais modifiable si votre cabinet arrondissait autrement.</li>
        <li data-icon="&#x1F4C5;"><strong>Début</strong> &mdash; à ne renseigner que si le composant ne démarre pas à la mise en location du bien : passage du micro-BIC au réel, mise en service échelonnée. Laissé vide, il suit la date de mise en location.</li>
        <li data-icon="&#x1F5C3;"><strong>Cumul repris</strong> &mdash; les amortissements déjà pratiqués par votre cabinet sur des exercices que vous ne saisirez pas ici. Ils s'ajoutent au cumul du bilan (2033-A case 030) et à la colonne « amortissements » du 2033-C, <strong>jamais</strong> à la charge de l'exercice.</li>
        <li data-icon="&#x1F6D1;"><strong>Couvert jusqu'à</strong> &mdash; le dernier exercice compris dans ce cumul repris, en général le dernier que votre cabinet a tenu. <strong>À renseigner dès que vous saisissez un cumul.</strong> Sans lui, le logiciel reconstitue ces mêmes exercices et les compte une seconde fois : votre case 030 vaut alors le double. Le champ passe en rouge quand un cumul est saisi sans sa borne.</li>
    </ul>
    <p>Une base saisie à la main porte l'étiquette <strong>saisi</strong> : elle est <strong>verrouillée</strong>, ne suit plus le prix du bien et n'est jamais recalculée automatiquement.</p>

    <h3>Un bandeau rouge en haut de l'écran</h3>
    <p>Il apparaît quand vos composants <strong>dépassent</strong> la base amortissable, et il chiffre
    l'écart. Tant qu'il est là, l'écran refuse d'enregistrer : la ventilation ne peut pas dépasser ce
    qu'il y a à ventiler.</p>
    <p>Le bouton <strong>« Recaler sur la base actuelle »</strong> règle la situation : chaque composant
    est recalculé depuis son pourcentage, sur la base d'aujourd'hui. Vos proportions sont conservées,
    seuls les montants changent. ⚠️ Une base que vous auriez <strong>saisie</strong> sera elle aussi
    réécrite — l'écran vous le demande avant de le faire.</p>

    <h3>Si vous changez la valeur du bien ou la part du terrain</h3>
    <p>La base amortissable en dépend directement. Les composants <strong>ventilés en pourcentage</strong>
    suivent tout seuls : leurs montants sont recalculés sur la nouvelle base, et leurs pourcentages ne
    bougent pas. C'est le sens même d'une ventilation en pourcentage.</p>
    <p>Une base <strong>saisie</strong>, en revanche, ne bouge pas &mdash; c'est sa raison d'être. Si la
    nouvelle base devient plus petite que la somme de vos montants saisis, rien n'est rogné dans votre dos :
    l'écart vous est signalé au moment de générer votre liasse, dans les contrôles de cohérence. À vous de
    décider ce qui doit changer.</p>

    <h3>Une ligne qui n'existe pas au catalogue</h3>
    <p>Le bouton <strong>« + Ajouter un composant »</strong> crée une ligne à nom libre &mdash; « Ascenseur », « Menuiseries extérieures », tout ce que votre liasse comporte et que la liste standard ignore. Elle naît en mode Montants, sur 10 ans et en « Autres immobilisations » : donnez-lui son nom, sa durée, sa base et sa vraie ligne 2033-C.</p>

    <p>Ventiler <strong>moins</strong> que la base amortissable est permis, et signalé sous le tableau : la part non rattachée à un composant ne s'amortit simplement pas. Ventiler <strong>plus</strong> est refusé à l'enregistrement.</p>

    <div class="ctx-warning" style="margin-top:12px;">
        <strong>Après modification :</strong> les exercices déjà enregistrés gardent leurs totaux figés. Lancez « Recalculer la chaîne » depuis la page Exercices pour qu'ils reprennent les nouvelles valeurs.
    </div>

    <div class="ctx-warning">
        <strong>Attention :</strong> Le terrain n'est pas amortissable. Sa part (15-20 % en général, parfois plus en zone urbaine) est déduite avant le calcul des composants. Sources : MeilleursAgents, votre notaire, l'acte d'acquisition. Le bouton « Estimer (DVF) » de la fiche du bien ne répond pas à cette question : il estime la valeur du bien entier, terrain compris.
    </div>

    <div class="ctx-warning" style="margin-top:12px;">
        <strong>Le piège à connaître :</strong> un cumul repris <em>sans</em> « Couvert jusqu'à »
        se compte deux fois. Le logiciel ne sait pas quels exercices votre cabinet a déjà couverts :
        il rejoue tout le plan depuis le début, et y ajoute votre montant. Un utilisateur a vu sa
        case 030 afficher 9 496 € pour 4 736 € déclarés &mdash; soit exactement son cumul, plus une
        reconstitution des mêmes années. Renseignez la borne, et les deux cessent de se superposer.
    </div>

    <div class="ctx-tip" style="margin-top:12px;">
        <strong>Un écart de moins de 1 % après la reprise ?</strong> C'est presque toujours une
        <strong>convention de prorata</strong>, et non une erreur. La première annuité se calcule ici
        au <strong>jour</strong> ; beaucoup de cabinets la calculent au <strong>mois</strong>, ce qui
        déplace environ 0,5 % &mdash; et l'écart se reporte ensuite à l'identique dans tous les
        cumuls. Les deux conventions sont admises. <strong>Conservez le chiffre de votre liasse
        déposée</strong> : c'est lui qui assure la continuité de votre bilan d'un exercice à l'autre.
    </div>

    <h3>Vos chiffres ne tombent pas juste ? Le rapport de diagnostic</h3>
    <p>Le bouton <strong>« Rapport de diagnostic »</strong>, en haut de cet écran et de la page
    Télédéclaration, rassemble en un texte tout ce qui produit vos amortissements : les valeurs saisies
    sur le bien, la base amortissable et sa provenance, chaque composant avec sa base, sa durée et sa
    date de départ, vos travaux et votre mobilier, puis les lignes de liasse qui en découlent et les
    écarts éventuels.</p>
    <p>Il ne part <strong>nulle part</strong> tout seul : copiez-le ou téléchargez-le, et joignez-le à
    votre message. Il ne contient ni votre nom, ni votre adresse, ni votre commune, ni votre adresse
    e-mail &mdash; mais il porte vos montants et les intitulés que vous avez saisis, alors relisez-le
    avant de l'envoyer.</p>

    <div class="ctx-tip" style="margin-top:12px;">
        <strong>Source :</strong> BOFiP BOI-ANNX-000115. La ventilation doit être « sincère et justifiée ». Il n'existe pas de grille obligatoire. En cas de doute, consultez un expert-comptable.
    </div>
</div>
