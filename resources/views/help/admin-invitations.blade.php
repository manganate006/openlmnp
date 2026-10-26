<div>
    <h3>Inscription sur invitation</h3>
    <p>Sur une instance réglée en <code>ALLOW_REGISTRATION=invite</code>, personne ne peut créer de compte de sa propre initiative : il faut un lien nominatif, émis depuis cet écran ou en ligne de commande.</p>

    <h3>Ce qu'une invitation garantit</h3>
    <ul>
        <li><strong>Une seule fois.</strong> Le lien cesse de fonctionner dès qu'un compte a été créé avec lui. Deux personnes ne peuvent pas entrer par la même invitation.</li>
        <li><strong>L'adresse est verrouillée.</strong> Le formulaire d'inscription affiche l'adresse invitée sans permettre de la modifier : une invitation émise pour quelqu'un ne peut pas servir à créer le compte d'un autre.</li>
        <li><strong>Une durée de vie.</strong> Par défaut 14 jours, réglable à l'émission ou par <code>INVITATION_TTL_DAYS</code>. La durée est figée au moment de l'envoi : changer le réglage ne rallonge pas les invitations déjà parties.</li>
    </ul>

    <h3>Relancer, révoquer</h3>
    <ul>
        <li><strong>Relancer</strong> régénère le lien et repousse l'expiration. <strong>Le lien précédent meurt</strong> : relancer, c'est remplacer, pas ajouter. C'est voulu — deux liens vivants pour la même personne, ce sont deux chemins à fermer le jour où il faut couper.</li>
        <li><strong>Révoquer</strong> coupe le lien immédiatement, et conserve la ligne pour la trace.</li>
    </ul>

    <div class="ctx-warning">
        <strong>Révoquer ne suspend pas un compte.</strong> Si la personne a déjà créé le sien, révoquer son invitation ne lui retire aucun accès : le lien était déjà consommé. Suspendre un compte existant est une autre opération, qui ne se fait pas depuis cet écran.
    </div>

    <h3>Le lien ne s'affiche qu'une fois</h3>
    <p>Seule une empreinte du jeton est enregistrée, jamais le jeton lui-même : le vol d'une sauvegarde ne livre donc aucun lien utilisable. La contrepartie est qu'il est impossible de le retrouver après coup — copiez-le si vous devez le transmettre vous-même, ou relancez l'invitation pour en obtenir un nouveau.</p>

    <div class="ctx-tip">
        <strong>Sans serveur de courriel :</strong> le lien est affiché à l'écran même quand l'envoi échoue, et la commande <code>php artisan openlmnp:invite adresse@exemple.fr</code> l'imprime toujours. Une instance auto-hébergée sans SMTP reste donc utilisable — il suffit de transmettre le lien par un autre canal.
    </div>

    <h3>Si les invitations semblent sans effet</h3>
    <p>Un bandeau s'affiche en haut de cet écran quand <code>ALLOW_REGISTRATION</code> ne vaut pas <code>invite</code>. Deux cas&nbsp;:</p>
    <ul>
        <li><code>false</code> &mdash; la page d'inscription n'existe pas du tout, et les liens émis mènent à une erreur.</li>
        <li><code>auto</code> ou <code>true</code> &mdash; l'inscription est ouverte à tous, l'invitation n'apporte donc rien.</li>
    </ul>

    <div class="ctx-warning">
        <strong>Sur une instance vierge, le premier compte devient administrateur.</strong> C'est vrai quel que soit le mode d'inscription : si vous invitez quelqu'un avant d'avoir créé votre propre compte, c'est cette personne qui administrera l'instance.
    </div>
</div>
