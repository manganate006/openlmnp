<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <title>Politique de confidentialité — {{ config('app.name', 'OpenLMNP') }}</title>
    <style>
        :root {
            --emerald-600: #059669;
            --emerald-700: #047857;
            --gray-900: #111827;
            --gray-700: #374151;
            --gray-500: #6b7280;
            --gray-200: #e5e7eb;
            --gray-50: #f9fafb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: var(--gray-900);
            background: var(--gray-50);
            line-height: 1.6;
        }
        header {
            background: #fff;
            border-bottom: 1px solid var(--gray-200);
            padding: 1.25rem 1.5rem;
        }
        header .brand {
            font-weight: 700;
            font-size: 1.125rem;
            color: var(--emerald-700);
            text-decoration: none;
        }
        main {
            max-width: 42rem;
            margin: 0 auto;
            padding: 2.5rem 1.5rem 4rem;
        }
        h1 {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }
        .updated {
            color: var(--gray-500);
            font-size: 0.875rem;
            margin-bottom: 2rem;
        }
        h2 {
            font-size: 1.125rem;
            margin-top: 2.25rem;
            margin-bottom: 0.75rem;
            color: var(--emerald-700);
        }
        p, li {
            color: var(--gray-700);
        }
        ul { padding-left: 1.25rem; }
        li { margin-bottom: 0.4rem; }
        .callout {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 0.5rem;
            padding: 1rem 1.25rem;
            margin: 1rem 0;
        }
        a { color: var(--emerald-600); }
        .back {
            display: inline-block;
            margin-top: 2.5rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <header>
        <a href="{{ url('/') }}" class="brand">OpenLMNP</a>
    </header>

    <main>
        <h1>Politique de confidentialité</h1>
        <p class="updated">Dernière mise à jour : {{ now()->translatedFormat('d F Y') }}</p>

        <p>
            OpenLMNP est un logiciel de comptabilité LMNP <strong>open source</strong>
            (<a href="https://github.com/manganate006/openlmnp" target="_blank" rel="noopener">code source public</a>).
            Il existe deux façons de l'utiliser, avec des conséquences différentes sur qui
            traite vos données personnelles et fiscales.
        </p>

        <h2>Auto-hébergement ou Cloud Pro : qui est responsable ?</h2>
        <div class="callout">
            <strong>Vous installez OpenLMNP vous-même</strong> (Docker, serveur personnel,
            etc.) : vous êtes seul responsable du traitement de vos données au sens du RGPD
            (article 4). L'éditeur du logiciel n'héberge rien, n'a accès à aucune de vos
            données et ne les collecte d'aucune façon.
        </div>
        <p>
            Si vous utilisez à la place l'offre gérée <strong>OpenLMNP Cloud Pro</strong>
            (hébergement sur <code>app.openlmnp.fr</code> souscrit via
            <a href="https://openlmnp.fr" target="_blank" rel="noopener">openlmnp.fr</a>),
            c'est alors OpenLMNP qui est responsable du traitement. La politique de
            confidentialité complète de cette offre (sous-traitants, hébergeur, durée de
            conservation contractuelle, etc.) est publiée sur
            <a href="https://openlmnp.fr/confidentialite" target="_blank" rel="noopener">openlmnp.fr/confidentialite</a>.
        </p>

        <h2>Données traitées par le logiciel</h2>
        <p>Quel que soit le mode d'utilisation, OpenLMNP peut stocker :</p>
        <ul>
            <li>Les données de votre compte : nom, adresse email, mot de passe (haché, jamais en clair)</li>
            <li>Vos données comptables et fiscales : biens immobiliers, revenus, charges, emprunts, amortissements</li>
            <li>Les justificatifs que vous joignez (factures, attestations, relevés) &mdash; dont le contenu peut être chiffré, voir « Coffre-fort des justificatifs » ci-dessous</li>
            <li>Des journaux techniques de connexion (horodatage, adresse IP)</li>
        </ul>

        <h2>Cookies et mesure d'audience</h2>
        <p>
            <strong>En auto-hébergement, l'application ne charge aucun outil de mesure et ne
            dépose aucun traceur.</strong> Elle n'a donc aucune question à vous poser : le
            bandeau de consentement n'existe tout simplement pas. C'est la même condition
            technique qui commande les deux — sans conteneur de mesure configuré, ni l'un ni
            l'autre n'est chargé.
        </p>
        <p>
            Sur l'instance Cloud Pro, une mesure d'audience est en place. Elle n'est activée
            qu'après votre accord, demandé par un bandeau à votre première visite ; refuser est
            aussi simple qu'accepter, et l'application fonctionne à l'identique dans les deux
            cas. Votre réponse est conservée six mois, puis la question vous est reposée.
        </p>
        <p>Dans tous les cas, l'application dépose les cookies suivants, nécessaires à son
            fonctionnement et qui ne servent à aucune mesure :</p>
        <ul>
            <li><strong>Session</strong> : vous garde connecté et protège les formulaires. Expire à la fermeture du navigateur.</li>
            <li><code>olmnp_demo_seen</code> : se souvient que vous avez essayé la démonstration, pour ne pas vous la reproposer. 30 jours.</li>
            <li><code>olmnp_consent</code> : mémorise votre réponse au bandeau, quand celui-ci existe. 6 mois.</li>
        </ul>
        <p>
            Pour revenir sur votre choix, supprimez les cookies de ce site dans votre
            navigateur : le bandeau réapparaîtra à la visite suivante.
        </p>

        <h2>Retours et avis sur le logiciel</h2>
        <p>
            Après un moment d'utilisation, l'application vous propose une fois de donner votre
            avis. Y répondre est facultatif, et ne pas y répondre n'a aucune conséquence.
        </p>
        <ul>
            <li><strong>Ce qui est enregistré</strong> : votre appréciation (positive ou négative), les cases que vous cochez, le message que vous écrivez s'il y en a un, le prénom et l'adresse email que vous saisissez s'il y en a, ainsi que le contexte technique (page, version du logiciel). Jamais un montant, jamais une donnée fiscale.</li>
            <li><strong>Au fur et à mesure, et pas seulement à l'envoi</strong> : votre appréciation et les cases cochées sont enregistrées <em>dès que vous cliquez</em>, sans attendre le bouton « Envoyer ». Autrement dit, si vous fermez la fenêtre en cours de route, ce que vous avez déjà indiqué nous est parvenu. Les cases sont enregistrées sous forme de repères non nominatifs (par exemple « prix » ou « reprise »), jamais leur libellé complet.</li>
            <li><strong>Si vous êtes dans la démonstration</strong> : on ne vous demande ni prénom ni accord de publication, parce qu'un retour laissé sur des données d'exemple ne sera jamais publié comme témoignage. Le compte de démonstration étant effacé au bout de quelques jours, votre retour lui survit mais n'est plus rattaché à personne.</li>
            <li><strong>Publication</strong> : votre message n'est publié nulle part sans la case de consentement explicite du formulaire. Sans elle, il reste un retour privé.</li>
            <li><strong>En auto-hébergement</strong> : le retour est enregistré dans <em>votre</em> base et <strong>rien n'est transmis à l'extérieur</strong>. L'écran de confirmation vous propose de l'envoyer vous-même par votre client mail, si vous le souhaitez. La fonctionnalité s'éteint avec <code>FEEDBACK_ENABLED=false</code>.</li>
            <li><strong>En Cloud Pro</strong> : le retour est transmis par email à l'auteur du logiciel, qui le traite comme une demande de support.</li>
            <li><strong>Conservation</strong> : les retours sont conservés sans limite de durée, car ils servent à faire évoluer le logiciel. Ils sont détachés de votre compte si celui-ci est supprimé. Vous pouvez en demander l'effacement à l'adresse ci-dessous.</li>
        </ul>
        <h2>Fonctions d'intelligence artificielle</h2>
        <p>
            OpenLMNP propose des fonctions optionnelles d'intelligence artificielle
            (lecture automatique de justificatifs, assistant d'utilisation). Elles sont
            <strong>désactivées par défaut</strong> et ne fonctionnent que si l'instance
            a été configurée pour cela.
        </p>
        <ul>
            <li>
                <strong>Ce qui est envoyé, et seulement quand vous le demandez</strong> : le
                document que vous soumettez à l'analyse (ou votre question à l'assistant), le
                nom du bien concerné et la liste des catégories comptables. Rien n'est envoyé
                tant que vous ne déclenchez pas explicitement l'action.
            </li>
            <li>
                <strong>Ce qui n'est jamais envoyé</strong> : votre identité, votre adresse
                email, votre SIREN, vos autres documents, vos clés et jetons d'accès.
                L'assistant ne consulte vos données comptables que si vous activez
                l'option correspondante dans <em>Mon profil</em>.
            </li>
            <li>
                <strong>À qui</strong> : au service d'IA configuré sur l'instance — par défaut
                <a href="https://openrouter.ai" target="_blank" rel="noopener">OpenRouter</a>,
                et à l'éditeur du modèle choisi. Une instance peut viser un autre service
                (Anthropic, Google) ou un serveur installé chez elle ; l'écran
                <em>Mon profil</em> indique lequel.
            </li>
            <li>
                <strong>Ce que nous demandons, et ce que nous ne pouvons pas garantir</strong> :
                chez OpenRouter, chaque requête est accompagnée d'une consigne de
                <strong>ne pas conserver vos données ni s'en servir pour entraîner des
                modèles</strong>. C'est une <em>préférence transmise à un intermédiaire</em>,
                pas un engagement que nous serions en mesure de tenir à sa place — et les
                autres services ne reçoivent même pas cette consigne, ce point relevant alors
                de leurs propres conditions d'utilisation, qu'il vous appartient de lire.
            </li>
            <li>
                <strong>Ce qui est conservé par OpenLMNP</strong> : uniquement une ligne de
                journal technique par appel (date, modèle utilisé, nombre de jetons, durée,
                coût, statut). <strong>Aucun contenu de document, aucune donnée extraite et
                aucune clé</strong> n'y figurent. Ce journal est purgé automatiquement après
                90 jours par défaut.
            </li>
            <li>
                <strong>Si vous utilisez le coffre-fort</strong> : il chiffre ce qui est
                <em>enregistré</em>, pas ce que vous choisissez de faire analyser. Un document
                soumis à l'analyse part <strong>en clair</strong> au service d'intelligence
                artificielle &mdash; c'est la seule façon qu'il ait de le lire &mdash; puis il est
                scellé au moment où il rejoint vos justificatifs. En revanche, un justificatif
                <em>déjà scellé</em> ne peut plus être analysé : le serveur ne sait plus le lire,
                et c'est exactement ce que le coffre garantit. Voir &laquo;&nbsp;Coffre-fort des
                justificatifs&nbsp;&raquo; ci-dessous.
            </li>
            <li>
                <strong>Rester chez vous</strong> : en auto-hébergement, les variables
                <code>AI_PROVIDER</code> et <code>AI_BASE_URL</code> peuvent viser un modèle
                exécuté sur votre propre machine (Ollama, LM Studio, vLLM). Aucune donnée ne
                sort alors de votre serveur — c'est la seule configuration où cela ne dépend
                de personne d'autre que de vous.
            </li>
        </ul>
        <p>
            <strong>Comment les désactiver</strong> : supprimez votre clé d'API dans
            <em>Mon profil</em>, désactivez l'option d'accès aux données de l'assistant, ou —
            en auto-hébergement — coupez l'ensemble avec <code>AI_ENABLED=false</code>. Aucune
            requête n'est alors émise vers un service d'IA.
        </p>

        <h2>Sécurité</h2>
        <p>
            Les mots de passe sont hachés (bcrypt) et ne sont jamais stockés en clair.
            L'accès aux données requiert une authentification. En auto-hébergement, la
            sécurisation du serveur (HTTPS, mises à jour, sauvegardes) relève de votre
            responsabilité.
        </p>

        <h2>Coffre-fort des justificatifs</h2>
        <p>
            Le coffre-fort est une fonctionnalité <strong>optionnelle</strong>, désactivée par
            défaut pour chaque compte. Une fois activé, il chiffre le <strong>contenu</strong>
            des fichiers que vous déposez &mdash; justificatifs comptables et photos de biens
            &mdash; avec une clé dérivée d'une phrase de passe que <strong>vous seul</strong>
            connaissez et que l'instance n'enregistre nulle part.
        </p>
        <ul>
            <li>
                <strong>Où se fait le déchiffrement</strong> : dans votre navigateur, jamais sur
                le serveur. Celui-ci ne détient que la moitié publique de votre clé &mdash; de
                quoi chiffrer un dépôt à tout moment, même coffre verrouillé, mais jamais de quoi
                relire quoi que ce soit. Votre phrase de passe ne quitte pas votre poste.
            </li>
            <li>
                <strong>Ce que le coffre ne couvre pas</strong> : le reste de votre comptabilité
                (libellés, montants, dates, adresses des biens) reste en clair en base ; le nom
                des fichiers, construit à partir de la date et du libellé, en raconte souvent
                l'essentiel ; et les documents que l'application produit &mdash; liasse fiscale,
                FEC, archive ZIP &mdash; sont fabriqués à partir de ces données en clair.
            </li>
            <li>
                <strong>Face à un serveur compromis</strong> : au repos, il n'y trouverait aucune
                clé et vos fichiers y resteraient illisibles. En revanche, il pourrait modifier
                la page qu'il vous sert pour capter votre phrase de passe au moment où vous la
                tapez. Le coffre protège la donnée <strong>au repos</strong> ; il ne remplace pas
                un serveur sain.
            </li>
            <li>
                <strong>Si vous perdez votre phrase de passe</strong> : les fichiers deviennent
                définitivement illisibles. Personne, pas même l'administrateur de l'instance, ne
                peut les récupérer &mdash; c'est la contrepartie directe de ce qui précède. Le
                lien « mot de passe oublié » vous rend l'accès à votre compte, pas à vos fichiers.
            </li>
            <li>
                <strong>Comptes de démonstration</strong> : le coffre-fort n'y est pas proposé.
                Un bac à sable est éphémère et ses identifiants sont publics ; y saisir une
                phrase de passe donnerait une fausse impression de confidentialité.
            </li>
        </ul>

        <h2>Vos droits (RGPD)</h2>
        <p>
            Vous disposez d'un droit d'accès, de rectification, de portabilité et de
            suppression de vos données.
        </p>
        <ul>
            <li><strong>En auto-hébergement</strong> : vous exercez ces droits directement, via l'application ou en accédant à votre base de données — vous en avez l'entière maîtrise.</li>
            <li><strong>En Cloud Pro</strong> : contactez <a href="mailto:contact@openlmnp.fr">contact@openlmnp.fr</a>, ou exportez vos données depuis l'application à tout moment.</li>
            <li><strong>Si votre coffre-fort est actif</strong> : la portabilité reste entière, mais l'archive de vos justificatifs est assemblée <em>dans votre navigateur</em> et réclame votre phrase de passe &mdash; le serveur ne peut pas la produire à votre place.</li>
        </ul>

        <h2>Durée de conservation</h2>
        <p>
            Les données comptables et fiscales sont soumises à une obligation légale de
            conservation d'au moins 6 ans en France. En auto-hébergement, cette conservation
            (et sa durée au-delà du minimum légal) reste sous votre responsabilité.
        </p>

        <h2>Transparence du code</h2>
        <p>
            OpenLMNP étant open source, vous pouvez vérifier vous-même, dans le
            <a href="https://github.com/manganate006/openlmnp" target="_blank" rel="noopener">dépôt GitHub public</a>,
            comment vos données sont traitées par le logiciel.
        </p>

        <h2>Contact</h2>
        <p>
            Pour toute question sur cette politique : <a href="mailto:contact@openlmnp.fr">contact@openlmnp.fr</a>
            ou via une <a href="https://github.com/manganate006/openlmnp/issues" target="_blank" rel="noopener">issue GitHub</a>.
        </p>

        <a href="{{ url('/') }}" class="back">&larr; Retour à l'application</a>
    </main>
</body>
</html>
