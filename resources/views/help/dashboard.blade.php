<div class="ctx-help">
    <h3>Votre tableau de bord</h3>
    <p>Le tableau de bord affiche une vue d'ensemble de votre activité LMNP pour l'année en cours, avec une comparaison N-1.</p>

    <h3>Les indicateurs</h3>
    <ul>
        <li data-icon="&#x1F4B0;"><strong>Recettes</strong> &mdash; Total des loyers encaissés sur l'année</li>
        <li data-icon="&#x1F4C4;"><strong>Charges</strong> &mdash; Total des dépenses déductibles enregistrées</li>
        <li data-icon="&#x1F3E0;"><strong>Amortissements</strong> &mdash; Dotation annuelle calculée automatiquement</li>
        <li data-icon="&#x1F4CA;"><strong>Résultat fiscal</strong> &mdash; Recettes - charges - amortissements (plafonné)</li>
    </ul>

    <h3>Premiers pas</h3>
    <p>Si vous venez de créer votre compte, commencez par :</p>
    <div class="ctx-step"><span class="ctx-step-num">1</span><span class="ctx-step-text">Ajouter votre bien immobilier</span></div>
    <div class="ctx-step"><span class="ctx-step-num">2</span><span class="ctx-step-text">Renseigner vos emprunts</span></div>
    <div class="ctx-step"><span class="ctx-step-num">3</span><span class="ctx-step-text">Saisir vos premières recettes et charges</span></div>

    <div class="ctx-tip">
        <strong>Astuce :</strong> Cliquez sur chaque indicateur pour accéder directement à la page correspondante.
    </div>

    @if (auth()->user()?->is_demo && ! auth()->user()?->demo_promoted_at)
        <h3>Votre bac à sable de démonstration</h3>
        <p>Vous n'êtes pas sur un compte ordinaire&nbsp;: vous explorez un <strong>bac à sable</strong>, rempli de données fictives, qui n'appartient qu'à vous et que personne d'autre ne voit. Il est <strong>effacé automatiquement</strong> au bout de {{ config('demo.ttl_hours') }}&nbsp;h.</p>

        <h3>Ce que le compte à rebours vous dit</h3>
        <ul>
            <li data-icon="&#x23F1;&#xFE0F;"><strong>La pastille en bas à droite</strong>, au-dessus du bouton d'aide &mdash; le temps qu'il reste, en continu</li>
            <li data-icon="&#x1F4AC;"><strong>Un bandeau</strong> &mdash; un rappel discret, qui ne bloque rien</li>
            <li data-icon="&#x26A0;&#xFE0F;"><strong>Une fenêtre</strong> &mdash; aux paliers qui comptent vraiment</li>
        </ul>
        <p>Les paliers se comptent en heures <strong>restantes</strong>, jamais écoulées&nbsp;: «&nbsp;il reste 6&nbsp;h&nbsp;» veut dire la même chose sur un bac à sable de {{ config('demo.ttl_hours') }}&nbsp;h que sur un bac à sable prolongé.</p>

        <h3>Garder votre travail</h3>
        <p>Deux sorties, proposées quand le temps se réduit&nbsp;:</p>
        <div class="ctx-step"><span class="ctx-step-num">1</span><span class="ctx-step-text"><strong>Prolonger</strong> &mdash; laissez une adresse e-mail et cochez le consentement&nbsp;: le bac à sable vit {{ config('demo.extended_ttl_days') }} jours de plus. Gratuit, sans carte bancaire, et <strong>une seule fois</strong>.</span></div>
        @if (filled(config('demo.links.pro')))
            <div class="ctx-step"><span class="ctx-step-num">2</span><span class="ctx-step-text"><strong>Garder mes données</strong> &mdash; votre dossier devient un vrai compte, repris tel quel. Rien à ressaisir, rien à réimporter.</span></div>
        @endif
        <p>Choisir <strong>Continuer la démonstration</strong> ne fait que repousser la question&nbsp;: le compte à rebours continue de tourner.</p>

        <div class="ctx-warning">
            <strong>Vous risquez de vous croire effacé alors que tout est là.</strong> Votre session ne dure que {{ config('session.lifetime') }}&nbsp;minutes, alors que le bac à sable, lui, vit {{ config('demo.ttl_hours') }}&nbsp;h. Revenez trois heures plus tard&nbsp;: vous serez déconnecté devant un dossier parfaitement vivant, sans moyen d'y rentrer. C'est le <strong>lien de reprise</strong>, envoyé quand vous prolongez, qui vous y ramène depuis n'importe quel appareil.
        </div>

        <div class="ctx-tip">
            <strong>Ce lien est une clé.</strong> Il vous reconnecte <em>sans mot de passe</em>&nbsp;: qui l'a en main entre dans votre bac à sable. Il arrive par e-mail, donc il traîne dans une boîte &mdash; ne le transférez à personne.
        </div>
    @endif

    @if (auth()->user()?->demo_promoted_at)
        <h3>Le sort des données d'exemple</h3>
        <p>Votre dossier est conservé&nbsp;: tout ce que vous aviez pendant l'essai est là. Reste à décider ce que deviennent les <strong>données d'exemple</strong> qui vous ont servi à découvrir le logiciel.</p>

        <ul>
            <li data-icon="&#x2705;"><strong>Ne garder que mes saisies</strong> &mdash; le bien d'exemple et ses exercices disparaissent, vos propres saisies restent, et <strong>les totaux sont recalculés</strong>. C'est l'option conseillée.</li>
            <li data-icon="&#x1F4E6;"><strong>Tout garder</strong> &mdash; le bien d'exemple reste à côté du vôtre. Vous pourrez le supprimer plus tard depuis la liste des biens.</li>
            <li data-icon="&#x1F5D1;&#xFE0F;"><strong>Repartir de zéro</strong> &mdash; le compte est vidé entièrement, <strong>y compris ce que vous avez saisi pendant l'essai</strong>.</li>
        </ul>

        <div class="ctx-warning">
            <strong>Ce choix ne se fait qu'une fois et rien n'est récupérable ensuite.</strong> Ce n'est pas un masquage&nbsp;: c'est une suppression en base, sans corbeille et sans annulation. Dans le doute, prenez «&nbsp;Tout garder&nbsp;»&nbsp;: c'est la seule option qui ne détruit rien, et vous pourrez toujours faire le ménage ensuite.
        </div>

        <div class="ctx-tip">
            <strong>Pourquoi les totaux se recalculent.</strong> Vos exercices fiscaux portent des montants <em>figés en base</em>, pas recalculés à l'affichage. Supprimer le bien d'exemple sans les reprendre laisserait vos exercices avec ses recettes et ses charges dedans &mdash; des chiffres faux, présentés comme justes. L'application s'en charge pour vous.
        </div>
    @endif
</div>
