<div class="ctx-help">
    <h3>Les baux</h3>
    <p>Pour une location meublée à l'année : saisissez le bail une fois, et OpenLMNP en tire le loyer de chaque mois. Chaque loyer devient une recette à part entière, que vous retrouvez dans <strong>Recettes</strong>.</p>

    <h3>Informations à saisir</h3>
    <ul>
        <li data-icon="&#x1F464;"><strong>Locataire</strong> : son nom, repris sur chaque loyer</li>
        <li data-icon="&#x1F4C5;"><strong>Début du bail</strong> et <strong>Fin du bail</strong> : laissez la fin vide tant que le bail court. Au départ du locataire, renseignez-la avant de saisir le bail suivant : deux baux du même bien ne peuvent pas se chevaucher</li>
        <li data-icon="&#x1F4B6;"><strong>Loyer hors charges</strong> et <strong>Charges</strong> : les montants mensuels prévus au bail</li>
        <li data-icon="&#x1F4C6;"><strong>Jour d'échéance</strong> : chaque loyer est daté de ce jour. Un 29, 30 ou 31 tombe le dernier jour des mois plus courts</li>
    </ul>

    <h3>Générer les loyers</h3>
    <p>Dans la liste, l'action <strong>Générer les loyers</strong> crée les loyers d'une année civile. Son <strong>Aperçu</strong> dit ce qui sera créé avant que rien ne s'écrive. Un exercice déjà clôturé est refusé. La relancer ne crée pas de doublon : un mois qui a déjà son loyer est laissé tel quel, même si vous l'avez modifié depuis.</p>
    <div class="ctx-warning">
        <strong>Les mois d'entrée et de sortie ne sont pas générés.</strong> Leur montant dépend de ce que prévoit le bail (jours réels, base de 30 jours, mois entier dû). L'aperçu les nomme : saisissez-les à la main dans Recettes, en source « Direct ».
    </div>

    <h3>Sur la liasse</h3>
    <p>En location meublée, ce que verse le locataire est une recette <strong>charges comprises</strong> : chaque loyer porte le loyer et les charges, et c'est ce montant qui va en ligne <strong>218</strong> du 2033-B. Les dépenses que vous payez réellement (copropriété, eau, électricité) se saisissent dans <strong>Charges</strong>, où elles sont déduites. La part de charges reste notée sur chaque loyer, pour l'affichage seulement.</p>
    <p>Le mode de charges, au forfait ou en provisions, ne change rien au calcul. En provisions, la régularisation annuelle sur les dépenses réelles se saisit à part.</p>

    <h3>Encaissement</h3>
    <p>Un loyer compte à la date de son échéance. Le jour où l'argent arrive se note sur la recette, dans <strong>Encaissé le</strong> : pour mémoire, aucun calcul ne le lit.</p>

    <div class="ctx-tip">
        <strong>Astuce :</strong> le bail signé, l'état des lieux et l'attestation d'assurance du locataire se joignent au bail lui-même, dans ses <strong>Pièces justificatives</strong>.
    </div>
</div>
