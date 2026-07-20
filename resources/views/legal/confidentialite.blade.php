<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
            <li>Les justificatifs que vous joignez (factures, attestations, relevés)</li>
            <li>Des journaux techniques de connexion (horodatage, adresse IP)</li>
        </ul>

        <h2>Sécurité</h2>
        <p>
            Les mots de passe sont hachés (bcrypt) et ne sont jamais stockés en clair.
            L'accès aux données requiert une authentification. En auto-hébergement, la
            sécurisation du serveur (HTTPS, mises à jour, sauvegardes) relève de votre
            responsabilité.
        </p>

        <h2>Vos droits (RGPD)</h2>
        <p>
            Vous disposez d'un droit d'accès, de rectification, de portabilité et de
            suppression de vos données.
        </p>
        <ul>
            <li><strong>En auto-hébergement</strong> : vous exercez ces droits directement, via l'application ou en accédant à votre base de données — vous en avez l'entière maîtrise.</li>
            <li><strong>En Cloud Pro</strong> : contactez <a href="mailto:contact@openlmnp.fr">contact@openlmnp.fr</a>, ou exportez vos données depuis l'application à tout moment.</li>
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
