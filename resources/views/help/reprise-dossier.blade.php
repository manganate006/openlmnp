<div class="ctx-help">
    <h3>Reprendre un dossier existant</h3>
    <p>Vous quittez un expert-comptable ou un autre logiciel&nbsp;? Cet assistant reprend votre plan d'amortissement et vos reports &agrave; partir de votre <strong>derni&egrave;re liasse</strong>, sans ressaisir un seul exercice pass&eacute;.</p>

    <h3>Ce qu'il faut avoir sous les yeux</h3>
    <ul>
        <li data-icon="&#x1F4C4;">Votre <strong>liasse 2033</strong> de la derni&egrave;re ann&eacute;e tenue par votre comptable</li>
        <li data-icon="&#x1F4D0;">Le <strong>tableau 2033-C</strong>, si vous voulez recopier son plan ligne &agrave; ligne</li>
    </ul>

    <h3>Les cinq &eacute;tapes</h3>
    <div class="ctx-step"><span class="ctx-step-num">1</span><span class="ctx-step-text"><strong>Votre situation</strong> &mdash; depuis quand vous louez, et la premi&egrave;re ann&eacute;e que vous tenez ici</span></div>
    <div class="ctx-step"><span class="ctx-step-num">2</span><span class="ctx-step-text"><strong>Votre bien</strong> &mdash; prix, frais, part du terrain, traitement des frais d'acquisition</span></div>
    <div class="ctx-step"><span class="ctx-step-num">3</span><span class="ctx-step-text"><strong>Vos amortissements</strong> &mdash; recopier les lignes de la liasse, ou r&eacute;partir automatiquement la base</span></div>
    <div class="ctx-step"><span class="ctx-step-num">4</span><span class="ctx-step-text"><strong>Vos reports</strong> &mdash; amortissements diff&eacute;r&eacute;s, cumul, d&eacute;ficits</span></div>
    <div class="ctx-step"><span class="ctx-step-num">5</span><span class="ctx-step-text"><strong>Contr&ocirc;le</strong> &mdash; l'&eacute;cart entre votre liasse et ce que l'application reconstitue</span></div>

    <h3>L'adresse du bien</h3>
    <p>Si le bien n'existe pas encore dans OpenLMNP, l'&eacute;tape 2 demande son identit&eacute;. Commencez &agrave; taper l'adresse&nbsp;: les propositions de la <strong>Base Adresse Nationale</strong> apparaissent, et en choisir une remplit d'un coup l'adresse, le <strong>code postal</strong> et la <strong>commune</strong>. La saisie &agrave; la main reste possible en toutes circonstances.</p>

    <h3>La saisie des dates</h3>
    <p>Tous les champs de date de l'assistant &mdash; mise en location, acquisition, d&eacute;but d'un composant &mdash; sont des <strong>s&eacute;lecteurs de calendrier</strong>. Cliquez sur l'ic&ocirc;ne pour choisir le jour, ou tapez la date&nbsp;: votre navigateur l'affiche &agrave; la fran&ccedil;aise et l'enregistre au format international. Il n'y a plus de gabarit &agrave; respecter.</p>

    <h3>O&ugrave; lire chaque chiffre</h3>
    <ul>
        <li data-icon="&#x1F522;"><strong>Prix d'acquisition</strong> (&eacute;tape 2)&nbsp;: le prix pay&eacute;
            pour le bien lui-m&ecirc;me, terrain compris. Les frais de notaire et d'agence, les travaux et le
            mobilier se saisissent &agrave; part. Sur votre balance, c'est la somme du compte 211 (terrain) et
            des comptes 213 et suivants (constructions).</li>
        <li data-icon="&#x1F522;"><strong>Amortissements diff&eacute;r&eacute;s</strong> &mdash; 2033-D case 870 (&agrave; d&eacute;faut&nbsp;: 2033-B case 318 cumul&eacute;e)</li>
        <li data-icon="&#x1F522;"><strong>Cumul d'amortissements</strong> &mdash; 2033-A case 030</li>
        <li data-icon="&#x1F522;"><strong>Total des immobilisations brutes</strong> &mdash; 2033-A case 044&nbsp;:
            le <strong>total</strong>, incorporelles comprises, et non la seule case 028. Vos frais de
            notaire figurent chez certains cabinets en incorporelles (case 014), chez d'autres avec les
            constructions&nbsp;: aucun des deux n'a tort, et le total ne change pas. C'est donc lui qu'on
            compare. La lecture par l'IA pr&eacute;remplit ce
            champ avec la somme des cases 028 et 014. S'il manque exactement le montant de vos frais, votre comptable les a
            <strong>pass&eacute;s en charges</strong> l'ann&eacute;e de l'acquisition&nbsp;: c'est le seul cas
            o&ugrave; l'&eacute;cran de contr&ocirc;le vous propose de les passer en charges vous aussi.</li>
        <li data-icon="&#x1F522;"><strong>D&eacute;ficits reportables</strong> &mdash; 2033-D cases 980 &agrave; 984</li>
    </ul>

    <h3>Le cumul repris, et jusqu'o&ugrave; il court</h3>
    <p>&Agrave; l'&eacute;tape&nbsp;3, si vous recopiez les <strong>amortissements d&eacute;j&agrave;
    pratiqu&eacute;s</strong> par votre cabinet, l'assistant retient tout seul qu'ils s'arr&ecirc;tent
    &agrave; la cl&ocirc;ture de l'exercice pr&eacute;c&eacute;dant celui que vous reprenez. C'est
    indispensable&nbsp;: sans cette borne, le logiciel reconstitue les m&ecirc;mes ann&eacute;es et les
    <strong>compte une seconde fois</strong> dans votre case&nbsp;030.</p>
    <p>Si votre cabinet s'est arr&ecirc;t&eacute; plus t&ocirc;t, corrigez la colonne
    <strong>&laquo;&nbsp;Couvert jusqu'&agrave;&nbsp;&raquo;</strong> dans l'&eacute;diteur
    d'amortissements&nbsp;: une borne que vous saisissez n'est jamais &eacute;cras&eacute;e.</p>

    <h3>Travaux et mobilier d&eacute;j&agrave; amortis</h3>
    <p>L'&eacute;diteur de l'&eacute;tape&nbsp;3 ne porte que les <strong>composants du bien</strong> (gros
    &oelig;uvre, toiture&hellip;)&nbsp;: des travaux ou du mobilier d&eacute;j&agrave; immobilis&eacute;s par
    votre cabinet ne s'y saisissent pas. L'&eacute;cran propose un lien direct vers vos pages <strong>Travaux</strong>
    et <strong>Mobilier</strong>, qui ont chacune leur propre section &laquo;&nbsp;Reprise d'une comptabilit&eacute;
    existante&nbsp;&raquo; (base, dotation recopi&eacute;e, cumul d&eacute;j&agrave; pratiqu&eacute;). Les
    oublier laisse un &eacute;cart inexpliqu&eacute; sur &laquo;&nbsp;Total des immobilisations brutes&nbsp;&raquo;
    &agrave; l'&eacute;tape&nbsp;5. Le cumul saisi l&agrave;-bas est born&eacute; &agrave; la cl&ocirc;ture de
    l'exercice pr&eacute;c&eacute;dent exactement comme celui des composants&nbsp;: pas de double comptage,
    m&ecirc;me en y revenant apr&egrave;s coup.</p>

    <h3>&laquo;&nbsp;Enregistrer mon plan&nbsp;&raquo; remplace, il n'ajoute pas</h3>
    <p>&Agrave; l'&eacute;tape&nbsp;3, le bouton d'enregistrement <strong>remplace</strong> votre plan
    d'amortissement par les lignes affich&eacute;es&nbsp;: tout composant d&eacute;coch&eacute; ou rest&eacute;
    &agrave; z&eacute;ro est <strong>supprim&eacute;</strong>. C'est voulu &mdash; c'est ce qui permet de
    recopier le plan de votre cabinet par-dessus la ventilation par d&eacute;faut &mdash; et c'est
    d&eacute;sormais <strong>annonc&eacute; avant le clic</strong>, chaque composant concern&eacute; &eacute;tant
    nomm&eacute; et compt&eacute;.</p>
    <p>Les lignes marqu&eacute;es d'un <strong>&#x25CB;</strong> sont des <strong>lignes de gabarit</strong>,
    propos&eacute;es par le catalogue&nbsp;: elles n'existent pas encore dans votre dossier, m&ecirc;me si elles
    s'affichent &agrave; 0,00&nbsp;&euro; comme les autres.</p>

    <h3>Un &eacute;cart de moins de 1&nbsp;% n'est pas une erreur</h3>
    <p>L'&eacute;tape&nbsp;5 signale &laquo;&nbsp;proche&nbsp;&raquo; et vous en donne d&eacute;sormais
    la raison. Le plus souvent, il ne manque rien &agrave; votre plan&nbsp;: c'est une
    <strong>convention de prorata</strong> qui diff&egrave;re. La premi&egrave;re annuit&eacute; se
    calcule ici au <strong>jour</strong>, beaucoup de cabinets la calculent au <strong>mois</strong>
    &mdash; environ 0,5&nbsp;% d'&eacute;cart, qui se reporte ensuite &agrave; l'identique dans tous
    les cumuls. Les deux se d&eacute;fendent. <strong>Gardez le chiffre de votre liasse
    d&eacute;pos&eacute;e</strong>&nbsp;: c'est lui qui assure la continuit&eacute; de votre bilan.</p>
    <h3>Un &eacute;cart sur la case 030 &eacute;gal &agrave; vos frais amortis</h3>
    <p>Beaucoup de cabinets rangent les frais d'acquisition en immobilisations incorporelles&nbsp;:
    leur amortissement est alors en case 016, alors qu'OpenLMNP l'inscrit en case 030 avec les
    constructions. Si l'&eacute;cart sur la case 030 vaut exactement ce qui a &eacute;t&eacute; amorti de vos
    frais et que la case 044 tombe juste, l'&eacute;tape&nbsp;5 marque la ligne <strong>&laquo;&nbsp;expliqu&eacute;&nbsp;&raquo;</strong>&nbsp;:
    vos frais sont amortis des deux c&ocirc;t&eacute;s, le total des amortissements (048) et la dotation
    de chaque ann&eacute;e sont les m&ecirc;mes. Il n'y a rien &agrave; corriger.</p>
    <p>Sans la case 044, l'&eacute;cran ne peut pas distinguer ce rangement de frais pass&eacute;s en
    charges&nbsp;: il place cette piste en t&ecirc;te, et vous invite &agrave; renseigner la case 044 pour
    trancher. Les frais d'acquisition se d&eacute;duisent soit en charges l'ann&eacute;e de l'achat, soit
    en les amortissant avec le bien&nbsp;: des frais que votre liasse amortit ne se repassent jamais en
    charges.</p>

    <h3>L'ordre des causes possibles</h3>
    <p>Quand un &eacute;cart reste &agrave; v&eacute;rifier, l'&eacute;tape&nbsp;5 commence par les causes que
    vos chiffres appuient par un <strong>montant</strong> (vos frais d'acquisition, par exemple), puis
    celles qu'une simple condition rend plausibles, puis les autres. Seules les causes qui peuvent
    jouer sur la ligne sont list&eacute;es&nbsp;: une date de mise en location ou une part de terrain ne
    changent pas le total des immobilisations brutes (044), qui porte le terrain et ignore les
    amortissements.</p>

    <h3>Un arrondi &agrave; l'euro n'est pas un d&eacute;passement</h3>
    <p>Votre liasse arrondit chaque ligne &agrave; l'euro, alors que la base amortissable (prix moins la
    part du terrain) tombe rarement sur un euro rond. Si les lignes lues d&eacute;passent la base de
    <strong>moins d'un euro par ligne</strong>, la recopie retire ces centimes de la plus grosse ligne
    et vous le signale&nbsp;; la dotation lue n'est pas modifi&eacute;e. Au-del&agrave;, la recopie est
    refus&eacute;e et l'&eacute;cart s'affiche au centime pr&egrave;s.</p>

    <h3>La dotation de votre cabinet est conserv&eacute;e</h3>
    <p>Chaque ligne recopi&eacute;e garde sa <strong>rubrique</strong> du 2033-C (constructions,
    installations techniques, agencements, autres) et la <strong>dotation</strong> lue sur votre
    liasse, m&ecirc;me quand elle ne vaut pas exactement la base divis&eacute;e par la dur&eacute;e. Elle
    reste affich&eacute;e et enregistr&eacute;e telle quelle dans l'&eacute;diteur, tant que vous ne
    modifiez ni la base ni la dur&eacute;e de la ligne.</p>

    <h3>Diff&eacute;r&eacute; n'est pas d&eacute;ficit</h3>
    <p>L'<strong>amortissement diff&eacute;r&eacute;</strong> est la part d'amortissement que vous n'avez pas pu d&eacute;duire parce qu'elle aurait cr&eacute;&eacute; un d&eacute;ficit&nbsp;: elle se reporte sans limite de dur&eacute;e. Le <strong>d&eacute;ficit</strong>, lui, vient de vos charges, et ne s'impute que sur vos b&eacute;n&eacute;fices de location meubl&eacute;e des <strong>dix</strong> ann&eacute;es suivantes.</p>

    <h3>Faire lire la liasse par l'IA</h3>
    <p>Si l'intelligence artificielle est activ&eacute;e sur votre instance, l'&eacute;tape&nbsp;3 propose une <strong>troisi&egrave;me m&eacute;thode</strong>&nbsp;: d&eacute;poser votre liasse (PDF ou photos), et laisser le mod&egrave;le lire le tableau <strong>2033-C</strong> ligne &agrave; ligne ainsi que les cases de report. Quatre garde-fous&nbsp;:</p>
    <ul>
        <li data-icon="&#x1F50D;"><strong>Relecture obligatoire</strong> &mdash; les lignes lues s'affichent face &agrave; votre document, avec un indice de confiance par ligne&nbsp;; rien n'est recopi&eacute; avant votre clic</li>
        <li data-icon="&#x2696;"><strong>Contr&ocirc;le de compl&eacute;tude</strong> &mdash; la somme des dotations lues est compar&eacute;e &agrave; la <strong>ligne 572</strong> de votre liasse&nbsp;: si les deux tombent d'accord, le tableau a &eacute;t&eacute; lu en entier</li>
        <li data-icon="&#x1F6AB;"><strong>Une case vide reste vide</strong> &mdash; une case absente de la liasse n'est jamais remplac&eacute;e par un z&eacute;ro qui ressemblerait &agrave; un montant lu</li>
        <li data-icon="&#x1F30D;"><strong>Le terrain n'est jamais recopi&eacute;</strong> &mdash; la ligne &laquo;&nbsp;Terrains&nbsp;&raquo; du 2033-C n'est pas amortissable et ne devient jamais un composant&nbsp;; sa part est d&eacute;j&agrave; exclue par le pourcentage de terrain de l'&eacute;tape&nbsp;2</li>
    </ul>
    <p><strong>D&eacute;poser le fichier</strong>&nbsp;: le grand cadre en pointill&eacute;s accepte le
    <strong>glisser-d&eacute;poser</strong> depuis votre explorateur de fichiers, et un clic n'importe
    o&ugrave; dedans ouvre le s&eacute;lecteur habituel. Le nom du fichier retenu s'affiche dans le cadre&nbsp;:
    tant qu'il n'y appara&icirc;t pas, rien n'a &eacute;t&eacute; choisi.</p>

    <p><strong>L'&eacute;cran de relecture</strong>&nbsp;: les <strong>lignes du 2033-C</strong> et
    les <strong>cases de report</strong> que le mod&egrave;le a lues s'affichent c&ocirc;te &agrave;
    c&ocirc;te en haut, et <strong>votre liasse en dessous</strong>, sur toute la largeur. Sa barre
    d'outils permet de <strong>changer de page</strong> et de <strong>zoomer</strong>&nbsp;; « Ouvrir
    dans un onglet » l'affiche en pleine page, si vous pr&eacute;f&eacute;rez comparer sur deux
    fen&ecirc;tres.</p>

    <p><strong>Si la ventilation d&eacute;passe la base amortissable</strong>, « Utiliser ces
    lignes » refuse la recopie <strong>en bloc</strong> — rien n'est &eacute;crit &agrave;
    moiti&eacute;. Une fen&ecirc;tre s'ouvre avec les trois rem&egrave;des&nbsp;: corriger le
    <strong>prix d'acquisition</strong> ou la <strong>part du terrain</strong> sans quitter
    l'&eacute;cran, <strong>d&eacute;cocher</strong> les lignes qui ne portent pas sur le b&acirc;ti
    (un terrain, un bien qui n'est pas celui-ci), ou revenir &agrave; l'&eacute;tape&nbsp;2.
    Le total se recalcule &agrave; chaque d&eacute;cochage.</p>

    <p><strong>Pendant la lecture</strong>&nbsp;: une fen&ecirc;tre s'ouvre au centre de
    l'&eacute;cran. Elle ne montre <strong>aucune &eacute;tape d'avancement</strong>, et c'est
    volontaire&nbsp;: votre document part en une seule fois et l'application attend la
    r&eacute;ponse, elle n'a aucun moyen de savoir o&ugrave; en est le mod&egrave;le. Comptez
    10 &agrave; 30&nbsp;secondes. Vous pouvez masquer cette fen&ecirc;tre (bouton, ou touche
    &Eacute;chap) sans rien annuler&nbsp;: le r&eacute;sultat, ou son &eacute;chec,
    s'affichera de toute fa&ccedil;on sur cet &eacute;cran.</p>

    <p>Votre consentement est demand&eacute; avant tout envoi, et vous pouvez toujours vous en passer&nbsp;: recopier les lignes &agrave; la main, ou pointer votre instance vers un mod&egrave;le install&eacute; chez vous.</p>

    <div class="ctx-warning">
        <strong>Un &eacute;cart n'est pas un &eacute;chec.</strong> L'&eacute;tape&nbsp;5 vous dit o&ugrave; chercher, en commen&ccedil;ant par ce que vos chiffres appuient. La cause la plus courante&nbsp;: vos frais d'acquisition ne sont pas rang&eacute;s sur la m&ecirc;me ligne que chez votre cabinet, ou votre cabinet les a pass&eacute;s en charges l'ann&eacute;e de l'achat.
    </div>

    <h3>&laquo;&nbsp;Revoir ma reprise&nbsp;&raquo; retrouve vos chiffres</h3>
    <p>Une fois &laquo;&nbsp;Terminer la reprise&nbsp;&raquo; cliqu&eacute;, votre m&eacute;thode et vos
    reports restent accessibles m&ecirc;me apr&egrave;s un rechargement de la page ou un nouvel onglet&nbsp;:
    l'&eacute;cran se r&eacute;veille depuis l'exercice d&eacute;j&agrave; enregistr&eacute;, avec la case
    &laquo;&nbsp;Total des immobilisations brutes&nbsp;&raquo; et le r&eacute;sultat fiscal lu sur votre
    liasse. Apr&egrave;s une lecture par l'IA, l'&eacute;tape&nbsp;3 montre le plan recopi&eacute;, pas un
    nouveau d&eacute;p&ocirc;t.</p>

    <h3>Apr&egrave;s la reprise, l'exercice suivant</h3>
    <p>Une reprise termin&eacute;e est le point de d&eacute;part de votre cha&icirc;ne d'exercices, m&ecirc;me
    si votre liasse ne portait ni amortissement diff&eacute;r&eacute; ni d&eacute;ficit&nbsp;: l'assistant de
    cl&ocirc;ture ne vous r&eacute;clame pas l'exercice pr&eacute;c&eacute;dent, que vous n'avez jamais tenu
    ici.</p>
    <p>Il vous propose d'office l'exercice qui suit votre derni&egrave;re liasse (2026 si votre cabinet a
    d&eacute;clar&eacute; 2025), puis, une fois celui-ci cl&ocirc;tur&eacute;, le suivant. Les ann&eacute;es
    couvertes par la liasse ne sont jamais propos&eacute;es&nbsp;: elles sont d&eacute;j&agrave; d&eacute;clar&eacute;es.</p>
</div>
