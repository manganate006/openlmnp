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
            <li data-icon="&#x1F4F7;"><strong>Photo</strong> &mdash; Ajoutez une photo pour identifier facilement votre bien. Elle est redimensionnée automatiquement.</li>
            <li data-icon="&#x1F3E0;"><strong>Nom</strong> &mdash; Un nom court pour retrouver votre bien (ex : « La Bastide », « Studio Paris »).</li>
            <li data-icon="&#x1F3F7;"><strong>Type de bien</strong> &mdash; Appartement, maison, studio... Utilisé pour les statistiques.</li>
            <li data-icon="&#x1F4C5;"><strong>Type de location</strong> &mdash; Saisonnier (Airbnb) ou longue durée (bail). Impacte le calcul fiscal.</li>
            <li data-icon="&#x1F4CD;"><strong>Adresse</strong> &mdash; L'adresse complète du bien.</li>
            <li data-icon="&#x1F4DD;"><strong>Notes</strong> &mdash; Informations libres pour votre usage personnel.</li>
        </ul>
    </div>

    {{-- Onglet Surfaces & Valeur --}}
    <div x-show="ctxTab === 'Surfaces & Valeur'" x-cloak>
        <h3>Surfaces et quote-part</h3>
        <p>Si vous louez une partie de votre résidence principale, la <strong>quote-part</strong> = surface louée &divide; surface totale. Elle s'applique automatiquement aux charges partagées et à l'amortissement.</p>

        <ul>
            <li data-icon="&#x1F4D0;"><strong>Surface totale</strong> &mdash; Surface déclarée aux impôts (loi Carrez ou habitable).</li>
            <li data-icon="&#x1F6CF;"><strong>Surface louée</strong> &mdash; Surface de la partie mise en location.</li>
        </ul>

        <h3>Acquisition et amortissement</h3>
        <ul>
            <li data-icon="&#x1F4B5;"><strong>Prix d'achat</strong> &mdash; Hors frais de notaire. Utilisé comme référence.</li>
            <li data-icon="&#x1F4B0;"><strong>Valeur vénale</strong> &mdash; Si vous possédiez le bien avant de le louer, indiquez sa valeur estimée au début de l'activité. C'est cette valeur qui sert de base à l'amortissement. Le bouton <strong>« Estimer (DVF) »</strong> propose un chiffre issu des ventes publiées pour votre commune, que vous pouvez reprendre ou ignorer.</li>
            <li data-icon="&#x1F3D7;"><strong>Part du terrain</strong> &mdash; Le terrain n'est pas amortissable (15-20 % en général). L'estimation DVF porte sur le bien entier, terrain compris : elle ne remplace pas cette ventilation.</li>
        </ul>

        <div class="ctx-warning">
            <strong>Important :</strong> Si la valeur vénale n'est pas renseignée, c'est le prix d'achat + frais de notaire qui sera utilisé comme base d'amortissement.
        </div>

        <h3>Estimer la valeur vénale (DVF)</h3>
        <p>Le bouton <strong>« Estimer (DVF) »</strong> interroge les <em>demandes de valeurs foncières</em> publiées par la DGFiP : les ventes réellement enregistrées dans votre commune, filtrées par type de bien, ramenées à un prix au mètre carré dont l'application prend la médiane.</p>
        <ul>
            <li data-icon="&#x1F50D;"><strong>Ce qui est envoyé</strong> &mdash; uniquement la commune et le type de bien. Ni l'adresse, ni le prix, ni aucune donnée de votre dossier.</li>
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
            <li data-icon="&#x1F4C5;"><strong>Date de début</strong> &mdash; Date à laquelle le bien a été mis en location pour la première fois. Les amortissements démarrent à cette date (prorata temporis la première année).</li>
            <li data-icon="&#x1F517;"><strong>Liens d'annonces</strong> &mdash; Ajoutez les URLs de vos annonces Airbnb, Booking, etc. pour les retrouver facilement.</li>
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
