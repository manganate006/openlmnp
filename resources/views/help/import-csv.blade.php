<div class="ctx-help">
    <h3>Import CSV g&eacute;n&eacute;rique</h3>
    <p>Importez un tableur de recettes, de charges, de mobilier ou de travaux &mdash; typiquement l'inventaire que vous a remis votre expert-comptable, ou l'export d'une plateforme autre qu'Airbnb.</p>

    <h3>Le parcours, en quatre &eacute;tapes</h3>
    <div class="ctx-step"><span class="ctx-step-num">1</span><span class="ctx-step-text">Choisissez le <strong>bien</strong>, la <strong>nature des lignes</strong> (un fichier = une nature) et d&eacute;posez le fichier</span></div>

    <div class="ctx-warning">
        <strong>V&eacute;rifiez la nature des lignes.</strong> Elle est pr&eacute;s&eacute;lectionn&eacute;e d'apr&egrave;s l'&eacute;cran d'o&ugrave; vous arrivez &mdash; <em>Recettes</em> propose &laquo;&nbsp;Recettes&nbsp;&raquo;, <em>Travaux</em> propose &laquo;&nbsp;Travaux&nbsp;&raquo; &mdash; mais elle reste modifiable, et c'est &agrave; vous de trancher. Importer des recettes en &laquo;&nbsp;Charges&nbsp;&raquo; <strong>inverse le signe de votre r&eacute;sultat fiscal</strong> sans qu'aucun montant ne paraisse anormal. Si les intitul&eacute;s de votre fichier semblent contredire votre choix, l'&eacute;cran d'aper&ccedil;u vous pose la question &mdash; il ne d&eacute;cide jamais &agrave; votre place.
    </div>
    <div class="ctx-step"><span class="ctx-step-num">2</span><span class="ctx-step-text">Regardez <strong>votre fichier tel qu'il est</strong>&nbsp;: ses dix premi&egrave;res lignes, avec ses propres intitul&eacute;s de colonnes</span></div>
    <div class="ctx-step"><span class="ctx-step-num">3</span><span class="ctx-step-text">V&eacute;rifiez la <strong>correspondance des colonnes</strong> propos&eacute;e, et corrigez-la si besoin</span></div>
    <div class="ctx-step"><span class="ctx-step-num">4</span><span class="ctx-step-text">Relisez l'<strong>aper&ccedil;u apr&egrave;s correspondance</strong>, puis importez</span></div>

    <h3>Comment lire l'&eacute;cran de correspondance</h3>
    <ul>
        <li data-icon="&#x1F9F1;"><strong>Un bloc = un champ d'OpenLMNP</strong> (Date de la charge, Montant TTC&hellip;), pas une colonne de votre fichier.</li>
        <li data-icon="&#x1F53D;"><strong>La liste d&eacute;roulante = les colonnes de votre fichier</strong>, dans leur ordre, avec leur intitul&eacute; d'origine. &laquo;&nbsp;&mdash; ignorer &mdash;&nbsp;&raquo; laisse le champ vide.</li>
        <li data-icon="&#x1F50E;"><strong>Sous la liste, un exemple</strong>&nbsp;: la premi&egrave;re valeur trouv&eacute;e dans la colonne choisie. C'est elle qui dit d'un coup d'&oelig;il si la correspondance est la bonne &mdash; en face de &laquo;&nbsp;Date de la charge&nbsp;&raquo;, vous devez lire une date.</li>
        <li data-icon="&#x2194;"><strong>Les tableaux plus larges que l'&eacute;cran se font d&eacute;filer horizontalement</strong>&nbsp;; la premi&egrave;re colonne, elle, reste visible.</li>
    </ul>

    <h3>Les compteurs de l'aper&ccedil;u</h3>
    <p>Ils se partagent <strong>exactement</strong> le nombre de lignes de votre fichier&nbsp;: <em>&agrave; importer</em> + <em>doublons</em> + <em>lignes en erreur</em> + <em>lignes vides ignor&eacute;es</em>. Si leur somme ne retombe pas sur le total annonc&eacute;, c'est un d&eacute;faut &mdash; signalez-le. La liste d'exemples d'erreurs, elle, s'arr&ecirc;te aux vingt premi&egrave;res&nbsp;; l'&eacute;cran le dit quand il y en a davantage.</p>

    <h3>Ce qui est lu automatiquement</h3>
    <ul>
        <li data-icon="&#x1F4C4;"><strong>S&eacute;parateur</strong> &mdash; virgule, point-virgule, tabulation ou barre verticale</li>
        <li data-icon="&#x1F4B6;"><strong>Montants</strong> &mdash; <em>1 234,56 &euro;</em> comme <em>1,234.56</em></li>
        <li data-icon="&#x1F4C5;"><strong>Dates</strong> &mdash; <em>JJ/MM/AAAA</em>, <em>AAAA-MM-JJ</em> et quelques variantes</li>
        <li data-icon="&#x1F50E;"><strong>Intitul&eacute;s de colonnes</strong> &mdash; sans tenir compte des accents ni de la casse</li>
    </ul>

    <h3>Doublons</h3>
    <p>Une ligne d&eacute;j&agrave; pr&eacute;sente n'est jamais r&eacute;&eacute;crite. Deux lignes sont tenues pour identiques quand elles ont&nbsp;:</p>
    <ul>
        <li><strong>recette</strong>&nbsp;: la m&ecirc;me <strong>r&eacute;f&eacute;rence de r&eacute;servation</strong> quand elle existe, sinon la m&ecirc;me <strong>date</strong> et le m&ecirc;me <strong>montant</strong>&nbsp;;</li>
        <li><strong>charge</strong>&nbsp;: la m&ecirc;me date, le m&ecirc;me montant et la m&ecirc;me <strong>cat&eacute;gorie</strong>&nbsp;;</li>
        <li><strong>mobilier et travaux</strong>&nbsp;: la m&ecirc;me date, le m&ecirc;me montant et le m&ecirc;me <strong>libell&eacute;</strong>.</li>
    </ul>
    <p>La r&egrave;gle vaut aussi <strong>&agrave; l'int&eacute;rieur du fichier</strong>&nbsp;: une ligne r&eacute;p&eacute;t&eacute;e n'est import&eacute;e qu'une fois, et l'aper&ccedil;u la marque &laquo;&nbsp;r&eacute;p&eacute;t&eacute; dans le fichier&nbsp;&raquo;. Deux versements r&eacute;ellement distincts mais identiques sur ces crit&egrave;res (m&ecirc;me jour, m&ecirc;me montant) seraient donc fusionn&eacute;s&nbsp;: v&eacute;rifiez ce compteur, et saisissez le second &agrave; la main si besoin.</p>

    <h3>Ce que devient le fichier d&eacute;pos&eacute;</h3>
    <p>Le tableau que vous d&eacute;posez est <strong>supprim&eacute; du serveur d&egrave;s l'import confirm&eacute;</strong> ; un aper&ccedil;u abandonn&eacute; en cours de route est effac&eacute; sous 24 heures. Rien &agrave; nettoyer de votre c&ocirc;t&eacute;.</p>

    <div class="ctx-warning">
        <strong>Export Airbnb :</strong> pr&eacute;f&eacute;rez l'&eacute;cran <em>Import Airbnb</em>. Lui seul reconstitue le montant brut &agrave; partir du net quand l'export ne d&eacute;taille pas la commission.
    </div>
</div>
