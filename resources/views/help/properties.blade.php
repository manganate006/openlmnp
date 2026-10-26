<div class="ctx-help">
    {{-- Aide par défaut (list) ou quand aucun onglet n'est sélectionné --}}
    <div x-show="!ctxTab" x-cloak>
        <h3>Vos biens immobiliers</h3>
        <p>Chaque bien représente un logement que vous mettez en location meublée. C'est le point de départ de toute la comptabilité.</p>

        <h3>Informations essentielles</h3>
        <ul>
            <li data-icon="&#x1F3E0;"><strong>Adresse et surfaces</strong> &mdash; Surface totale du logement et surface louée. La quote-part est calculée automatiquement.</li>
            <li data-icon="&#x1F4B5;"><strong>Valeur vénale</strong> &mdash; Valeur estimée du bien au début de l'activité. C'est cette valeur (pas le prix d'achat) qui sert de base à l'amortissement. Le bouton <strong>« Estimer (DVF) »</strong>, à côté du champ, la calcule à partir des ventes réellement enregistrées dans votre commune.</li>
            <li data-icon="&#x1F3D7;"><strong>Part du terrain</strong> &mdash; Le terrain n'est pas amortissable. En général 15-20 %. L'estimation DVF ne la donne pas : elle porte sur la valeur du bien entier. Pour le terrain seul, appuyez-vous sur MeilleursAgents, votre notaire ou l'acte d'acquisition.</li>
            <li data-icon="&#x26A1;"><strong>DPE</strong> &mdash; Le bouton <strong>« Retrouver le DPE »</strong>, à côté du numéro de DPE, le cherche dans la base de l'ADEME à partir de l'adresse du bien. La fiche affiche ensuite ce que votre classe permet (bail meublé, meublé de tourisme) et vous alerte six mois avant son expiration.</li>
        </ul>

        <div class="ctx-tip">
            <strong>Astuce :</strong> L'assistant « Premier lancement » vous guide pas à pas si c'est votre premier bien.
        </div>
    </div>

    {{-- Onglet Général --}}
    <div x-show="ctxTab === 'Général'" x-cloak>
        <h3>Informations générales</h3>
        <p>Identifiez votre bien avec ses caractéristiques de base.</p>

        <ul>
            <li data-icon="&#x1F4F7;"><strong>Photo</strong> &mdash; Ajoutez une photo pour identifier facilement votre bien. Elle est redimensionnée automatiquement. Si vous avez activé le coffre-fort, elle est chiffrée comme un justificatif.</li>
            <li data-icon="&#x1F3E0;"><strong>Nom</strong> &mdash; Un nom court pour retrouver votre bien (ex : « La Bastide », « Studio Paris »).</li>
            <li data-icon="&#x1F3F7;"><strong>Type de bien</strong> &mdash; Appartement, maison, studio... Utilisé pour les statistiques.</li>
            <li data-icon="&#x1F4C5;"><strong>Type de location</strong> &mdash; Saisonnier (Airbnb) ou longue durée (bail). Pré-remplit le classement micro-BIC (onglet Location), sans effet sur le régime réel.</li>
            <li data-icon="&#x1F4CD;"><strong>Adresse</strong> &mdash; Commencez &agrave; taper&nbsp;: les adresses de la <strong>Base Adresse Nationale</strong> vous sont propos&eacute;es, et en choisir une remplit d'un coup l'adresse, le <strong>code postal</strong> et la <strong>commune</strong>. La saisie &agrave; la main reste possible &mdash; si le service ne r&eacute;pond pas, vous n'avez simplement aucune proposition, et rien ne bloque l'enregistrement.</li>
            <li data-icon="&#x1F4DD;"><strong>Notes</strong> &mdash; Informations libres pour votre usage personnel.</li>
        </ul>

        <div class="ctx-warning">
            <strong>La photo ne s'affiche pas&nbsp;? Cliquez dessus.</strong> Coffre-fort activ&eacute; et verrouill&eacute;, l'aper&ccedil;u n'appara&icirc;t pas : le serveur n'a pas la cl&eacute; pour le produire, et c'est voulu. Une fen&ecirc;tre vous demande votre phrase de passe, une seule fois pour la visite, et la photo se d&eacute;chiffre <strong>dans votre navigateur</strong>. Le fichier est intact &mdash; ne le red&eacute;posez pas en croyant l'avoir perdu. Et vous pouvez toujours en d&eacute;poser un nouveau sans d&eacute;verrouiller&nbsp;: seule la lecture demande la phrase.
        </div>

        <h3>Diagnostic de performance énergétique (DPE)</h3>
        <p>Sous l'adresse, quatre champs reprennent le DPE du bien : son numéro, sa classe (A à G), sa date d'établissement et sa date de fin de validité. Tous restent modifiables à la main, même après une recherche.</p>

        <ul>
            <li data-icon="&#x1F50D;"><strong>« Retrouver le DPE »</strong> &mdash; Géocode l'adresse du bien (Géoplateforme, IGN) puis interroge la base de l'ADEME. Si plusieurs DPE existent à cette adresse (cas fréquent en immeuble), ils sont tous listés : à vous de choisir celui de votre logement, rien n'est repris automatiquement. Vous pouvez aussi chercher directement par numéro de DPE si vous l'avez sous la main.</li>
            <li data-icon="&#x2696;"><strong>Ce que votre classe permet</strong> &mdash; Dès qu'une classe est renseignée, la fiche affiche un verdict <strong>« Décent » / « Non décent »</strong> pour chaque façon de louer applicable à ce bien (bail meublé si location longue durée ou mixte, meublé de tourisme si saisonnier ou mixte — sauf s'il s'agit de votre résidence principale), avec la prochaine échéance du calendrier légal. Un logement non décent ne rend pas le bail nul : le locataire peut exiger les travaux, et le juge réduire ou suspendre le loyer.</li>
            <li data-icon="&#x23F0;"><strong>Alerte d'expiration</strong> &mdash; Un DPE expiré, ou qui expire dans moins de six mois, est signalé sur la fiche et dans les alertes du tableau de bord.</li>
        </ul>

        <div class="ctx-tip">
            <strong>À retenir :</strong> comme pour l'estimation DVF, la recherche ne part que sur votre clic : ouvrir la fiche n'envoie rien. Seule l'adresse du bien est transmise à l'IGN puis à l'ADEME.
        </div>
    </div>

    {{-- Onglet Surfaces & Valeur --}}
    <div x-show="ctxTab === 'Surfaces & Valeur'" x-cloak>
        <h3>Situation du logement</h3>
        <ul>
            <li data-icon="&#x1F3E0;"><strong>Logement distinct</strong> &mdash; vous n'y habitez pas. Quote-part = 100 % si vous ne déclarez que la partie louée.</li>
            <li data-icon="&#x1F6AA;"><strong>Pièce(s) de votre résidence principale</strong> &mdash; la <strong>quote-part</strong> (surface louée &divide; surface totale) s'applique automatiquement aux charges partagées et à l'amortissement.</li>
            <li data-icon="&#x1F9F1;"><strong>Logement indépendant dans votre résidence principale</strong> (ou sur la même propriété) &mdash; studio avec entrée propre, rez-de-chaussée aménagé, dépendance. Pour la loi, c'est un meublé <strong>distinct</strong> de la résidence principale : pas de limite de 120 jours, pas d'art. 35 bis (ses exonérations ne couvrent qu'une partie de la résidence principale elle-même), <strong>CFE due en principe</strong>, et <strong>taxe d'habitation sur les résidences secondaires (THRS)</strong> si un membre du foyer l'utilise une partie de l'année. Surface totale = la propriété entière ; surface louée = le seul logement indépendant ; prix et valeur vénale = ceux de la propriété entière. Marquez « dédiées » les charges propres au logement (compteur séparé, travaux, CFE).</li>
        </ul>

        <h3>Surfaces et quote-part</h3>
        <ul>
            <li data-icon="&#x1F4D0;"><strong>Surface totale</strong> &mdash; Surface déclarée aux impôts (loi Carrez ou habitable). Obligatoire&nbsp;: sans elle, la quote-part ne peut pas être calculée. Pour un logement indépendant, celle de la propriété entière.</li>
            <li data-icon="&#x1F6CF;"><strong>Surface louée</strong> &mdash; Surface de la partie mise en location. Vous pouvez saisir <strong>soit cette surface, soit directement la quote-part</strong> juste en dessous&nbsp;: l'autre valeur se calcule automatiquement, en direct.</li>
        </ul>

        <h3>Acquisition et amortissement</h3>
        <ul>
            <li data-icon="&#x1F4B5;"><strong>Prix d'achat</strong> &mdash; Hors frais de notaire. Utilisé comme référence.</li>
            <li data-icon="&#x1F4B0;"><strong>Valeur vénale</strong> &mdash; Si vous possédiez le bien avant de le louer, indiquez sa valeur estimée au début de l'activité. C'est cette valeur qui sert de base à l'amortissement. Le bouton <strong>« Estimer (DVF) »</strong> propose un chiffre issu des ventes publiées pour votre commune, que vous pouvez reprendre ou ignorer.</li>
            <li data-icon="&#x1F3D7;"><strong>Part du terrain</strong> &mdash; Le terrain n'est pas amortissable (15-20 % en général). L'estimation DVF porte sur le bien entier, terrain compris : elle ne remplace pas cette ventilation.</li>
        </ul>

        <div class="ctx-warning">
            <strong>Ce qui s'amortit, exactement :</strong> la valeur vénale si vous l'avez renseignée,
            sinon le prix d'achat &mdash; moins la part du terrain, et ramené à votre quote-part si le
            bien n'est pas loué en entier.
        </div>

        <h3>Vos frais de notaire et d'agence</h3>
        <p>Ils ne sont <strong>pas</strong> compris dans le prix d'achat ci-dessus, et le champ
        <strong>« Traitement des frais d'acquisition »</strong> décide de ce qu'ils deviennent. Quatre
        réponses possibles, à aligner sur ce qu'a fait votre comptable :</p>
        <ul>
            <li data-icon="&#x1F4C4;"><strong>Amortis séparément</strong> (par défaut) &mdash; ils forment
            une immobilisation à part, amortie sur sa propre durée (25 ans en général). Votre base
            amortissable ne change pas. Au bilan, ils apparaissent avec les constructions
            (case&nbsp;028), pas sur une ligne à eux.</li>
            <li data-icon="&#x1F3E0;"><strong>Intégrés au coût du bien</strong> &mdash; ils rejoignent le
            prix : ils se répartissent alors terrain / bâti comme lui, donc la part terrain ne s'amortit
            pas, et le reste suit la durée de chaque composant. <strong>Votre base amortissable
            augmente.</strong></li>
            <li data-icon="&#x1F4B8;"><strong>Passés en charges</strong> &mdash; déduits en une fois
            l'année de l'achat. Ils ne figurent alors dans aucune immobilisation.</li>
            <li data-icon="&#x274C;"><strong>Non repris</strong> &mdash; ni charge ni immobilisation.</li>
        </ul>
        <p>Une <strong>valeur vénale</strong> renseignée prime sur tout cela : c'est la valeur d'entrée
        du bien dans votre activité de loueur, et des frais payés à l'achat, souvent des années plus
        tôt, ne s'y ajoutent pas. Le formulaire vous le signale si la combinaison se présente.</p>
        <p>Pour retrouver d'où vient chaque montant de votre liasse, l'annexe
        <strong>« Détail des immobilisations »</strong> &mdash; dans le PDF et sur l'écran de
        télédéclaration &mdash; donne la ligne du 2033-C de chaque actif.</p>

        <h3>Si vous changez le prix ou la part du terrain</h3>
    <p>Ces deux champs déterminent la <strong>base amortissable</strong> : ce qui s'amortit, une fois
    le terrain retiré. La changer déplace donc vos amortissements.</p>
    <p>Vos composants réglés <strong>en pourcentage</strong> suivent tout seuls — c'est le sens d'un
    pourcentage, et rien ne vous sera signalé. Ceux dont le montant est <strong>saisi en euros</strong>
    ne bougent pas, et c'est voulu : ce sont les chiffres de votre comptable. Mais leur somme peut
    alors dépasser la nouvelle base.</p>
    <p>Dans ce cas, un message vous le dit <strong>au moment où vous enregistrez</strong>, avec le
    montant du dépassement et un bouton pour aller ajuster. N'attendez pas la génération de votre
    liasse pour le découvrir.</p>

    <h3>Estimer la valeur vénale (DVF)</h3>
        <p>Le bouton <strong>« Estimer (DVF) »</strong> interroge les <em>demandes de valeurs foncières</em> publiées par la DGFiP : les ventes réellement enregistrées dans votre commune, filtrées par type de bien, ramenées à un prix au mètre carré dont l'application prend la médiane.</p>
        <ul>
            <li data-icon="&#x1F50D;"><strong>Ce qui est envoyé</strong> &mdash; uniquement la commune et le type de bien. Ni l'adresse, ni le prix, ni aucune donnée de votre dossier.</li>
            <li data-icon="&#x1F4CD;"><strong>L'autocompl&eacute;tion d'adresse, elle, envoie l'adresse</strong> &mdash; c'est tout son objet. Le d&eacute;but de ce que vous tapez part &agrave; la Base Adresse Nationale (servie par la G&eacute;oplateforme de l'IGN), &agrave; partir de trois caract&egrave;res. Un administrateur peut la couper&nbsp;: <code>ADDRESS_LOOKUP_ENABLED=false</code>.</li>
            <li data-icon="&#x1F4CA;"><strong>Ce qui vous est montré</strong> &mdash; le prix au m², le nombre de ventes retenues et les millésimes utilisés. Lisez ce nombre : une médiane sur trois ventes ne vaut pas une médiane sur trois mille.</li>
            <li data-icon="&#x26A0;"><strong>Quand rien ne s'affiche</strong> &mdash; si la commune ne fournit pas assez de ventes comparables, l'application élargit aux années voisines, puis renonce plutôt que d'afficher un chiffre trompeur. L'Alsace-Moselle et Mayotte ne sont pas couvertes par cette source : elles relèvent du livre foncier et d'un cadastre distinct.</li>
        </ul>

        <div class="ctx-tip">
            <strong>À retenir :</strong> l'estimation est une aide à la saisie, pas une expertise. C'est vous qui reprenez ou non la valeur proposée, et c'est le détail de l'échantillon qui la justifie en cas de contrôle.
        </div>
    </div>

    {{-- Onglet Location --}}
    <div x-show="ctxTab === 'Location'" x-cloak>
        <h3>Informations de location</h3>
        <ul>
            <li data-icon="&#x1F4C5;"><strong>Date de début</strong> &mdash; Date à laquelle le bien a été mis en location pour la première fois. Les amortissements démarrent à cette date (prorata temporis la première année). La part non déduite la première année se déduit à la fin du plan, qui dure alors une année de plus.</li>
            <li data-icon="&#x1F517;"><strong>Liens d'annonces</strong> &mdash; Ajoutez les URLs de vos annonces Airbnb, Booking, etc. pour les retrouver facilement.</li>
        </ul>

        <h3>Classement micro-BIC</h3>
        <p>Détermine l'abattement et le plafond du <strong>simulateur micro-BIC</strong> &mdash; sans effet sur le régime réel, qui ne s'en sert pas.</p>
        <ul>
            <li data-icon="&#x1F3D5;"><strong>Meublé de tourisme non classé</strong> &mdash; cas le plus courant d'un Airbnb sans classement officiel.</li>
            <li data-icon="&#x2B50;"><strong>Meublé de tourisme classé</strong> &mdash; classement délivré par la préfecture ou un organisme agréé (Atout France...).</li>
            <li data-icon="&#x1F4DD;"><strong>Location meublée (bail)</strong> &mdash; location longue durée classique.</li>
        </ul>

        <div class="ctx-tip">
            <strong>Astuce :</strong> La date de début de location est cruciale pour le calcul du prorata temporis de la première année d'amortissement.
        </div>
    </div>

    {{-- Onglet Travaux --}}
    <div x-show="ctxTab === 'Travaux'" x-cloak>
        <h3>Travaux sur le bien</h3>
        <p>Les travaux réalisés sur votre bien locatif peuvent être amortis ou passés en charges selon leur nature.</p>

        <ul>
            <li data-icon="&#x2B06;"><strong>Travaux d'amélioration</strong> &mdash; Augmentent la valeur du bien (rénovation cuisine, salle de bain, isolation...). Amortis sur 10 à 15 ans.</li>
            <li data-icon="&#x1F527;"><strong>Travaux de réparation</strong> &mdash; Maintiennent le bien en état (remplacement chauffe-eau, peinture...). Passés en charges directes.</li>
        </ul>

        <div class="ctx-tip">
            <strong>Astuce :</strong> Les travaux réalisés avant le début de l'activité peuvent aussi être amortis.
        </div>
    </div>

    {{-- Onglet Mobilier --}}
    <div x-show="ctxTab === 'Mobilier'" x-cloak>
        <h3>Mobilier et équipements</h3>

        <h3>Règle des 600 EUR</h3>
        <ul>
            <li data-icon="&#x2B06;"><strong>Plus de 600 EUR TTC</strong> &mdash; Le meuble est <strong>amorti</strong> sur sa durée de vie (5 à 10 ans)</li>
            <li data-icon="&#x2B07;"><strong>Moins de 600 EUR TTC</strong> &mdash; Passe directement en <strong>charge</strong> déductible</li>
        </ul>

        <h3>Durées courantes</h3>
        <ul>
            <li data-icon="&#x1F6CB;"><strong>Literie, canapé</strong> &mdash; 10 ans</li>
            <li data-icon="&#x1F4FA;"><strong>Électroménager, TV</strong> &mdash; 5 à 7 ans</li>
            <li data-icon="&#x1FA91;"><strong>Petit mobilier</strong> &mdash; 5 ans</li>
            <li data-icon="&#x1F5A5;"><strong>Informatique</strong> &mdash; 3 ans</li>
        </ul>
    </div>

    {{-- Onglet Composants --}}
    <div x-show="ctxTab === 'Composants'" x-cloak>
        <h3>Composants d'amortissement</h3>
        <p>Votre bien est décomposé en composants, chacun amorti sur sa propre durée (obligatoire au régime réel).</p>

        <ul>
            <li data-icon="&#x1F3D7;"><strong>Gros &oelig;uvre</strong> &mdash; 50 ans (~50 %)</li>
            <li data-icon="&#x1F3E0;"><strong>Toiture</strong> &mdash; 25 ans (~10 %)</li>
            <li data-icon="&#x26A1;"><strong>Électricité</strong> &mdash; 25 ans (~10 %)</li>
            <li data-icon="&#x1F6BF;"><strong>Plomberie / sanitaire</strong> &mdash; 15 ans (~10 %)</li>
            <li data-icon="&#x1F3A8;"><strong>Agencements intérieurs</strong> &mdash; 15 ans (~15 %)</li>
            <li data-icon="&#x2600;"><strong>Étanchéité</strong> &mdash; 15 ans (~5 %)</li>
        </ul>

        <p>Pour une <strong>maison individuelle</strong>, ajoutez si besoin : piscine (15-20 ans), climatisation (15 ans), cuisine équipée (10 ans), VRD/portail (25-30 ans), terrasse/clôture (15-20 ans).</p>

        <div class="ctx-warning">
            <strong>Attention :</strong> Le total des pourcentages doit faire 100 %. La part du terrain est déduite avant le calcul.
        </div>
    </div>
</div>
