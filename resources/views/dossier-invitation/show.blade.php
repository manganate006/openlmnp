<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <meta name="robots" content="noindex, nofollow">
    <title>Invitation à un dossier — {{ config('app.name') }}</title>
    {{--
        ⚠️ Page AUTONOME, hors panel Filament (même raison qu'InvitationController :
        jeton en SEGMENT d'URL, jamais de GTM dessus — voir routes/web.php) : elle ne
        peut donc pas consulter `filament/partials/theme-tokens.blade.php` (ses
        jetons suivent la palette Filament, injectée seulement DANS le panel). Les
        couleurs ci-dessous sont des LITTÉRAUX assumés (même exception que l'ancienne
        version de ce fichier), repris de la palette émeraude de la marque — écran 7,
        variante B, décision du 2026-10-15 : « la page d'acceptation reprend la
        marque ».
    --}}
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #f0fdf9; color: #0f172a; padding: 1.5rem;
        }
        @media (prefers-color-scheme: dark) { body { background: #0f172a; color: #e2e8f0; } }
        .carte {
            max-width: 32rem; width: 100%; background: #ffffff; border: 1px solid #a7f3d0;
            border-radius: .75rem; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,.08);
        }
        @media (prefers-color-scheme: dark) { .carte { background: #1e293b; border-color: #065f46; } }
        .marque { display: flex; align-items: center; gap: .5rem; font-weight: 700; margin-bottom: 1.25rem; color: #065f46; }
        @media (prefers-color-scheme: dark) { .marque { color: #6ee7b7; } }
        .marque-point { display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem;
            border-radius: 999px; background: #059669; color: #ffffff; font-size: .8rem; font-weight: 700; }
        .pastille { display: inline-flex; align-items: center; gap: .3rem; padding: .15rem .6rem; border-radius: 999px;
            font-size: .72rem; font-weight: 700; background: #ecfdf5; color: #065f46; margin-bottom: .6rem; }
        @media (prefers-color-scheme: dark) { .pastille { background: rgba(6,95,70,.4); color: #6ee7b7; } }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { line-height: 1.6; margin: 0 0 1rem; word-break: break-word; }
        .carte-dossier { display: flex; flex-direction: column; gap: .15rem; padding: .85rem 1rem; border-radius: .6rem;
            background: #f0fdf9; border: 1px solid #a7f3d0; margin-bottom: 1rem; }
        @media (prefers-color-scheme: dark) { .carte-dossier { background: rgba(6,95,70,.25); border-color: #065f46; } }
        .carte-dossier b { font-size: .95rem; }
        .carte-dossier span { font-size: .8rem; opacity: .8; }
        .actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: .5rem; }
        button, a.bouton {
            display: inline-block; padding: .55rem 1.1rem; border-radius: .5rem;
            background: #059669; color: #ffffff; text-decoration: none; font-weight: 600;
            border: none; cursor: pointer; font: inherit;
        }
        a.bouton.secondaire, button.secondaire { background: transparent; color: #059669; border: 1px solid #059669; }
        a.lien { color: #059669; }
        .discret { font-size: .875rem; opacity: .75; }
        .separateur { margin: 1.5rem 0; border: none; border-top: 1px solid #a7f3d0; }
        @media (prefers-color-scheme: dark) { .separateur { border-top-color: #065f46; } }
        form.membre label { display: block; font-size: .875rem; font-weight: 600; margin: 0 0 .25rem; }
        form.membre input {
            width: 100%; box-sizing: border-box; padding: .5rem .65rem; margin-bottom: .75rem;
            border-radius: .5rem; border: 1px solid #cbd5e1; font: inherit; background: #ffffff; color: inherit;
        }
        @media (prefers-color-scheme: dark) { form.membre input { background: #0f172a; border-color: #334155; } }
        .erreur-champ { color: #dc2626; font-size: .8rem; margin: -.5rem 0 .75rem; }
        .comptes { display: flex; flex-direction: column; gap: .5rem; margin-bottom: .5rem; }
        .compte { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .6rem .75rem;
            border-radius: .5rem; font-size: .85rem; }
        .compte.mauvais { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .compte.bon { background: #f0fdf9; border: 1px solid #a7f3d0; color: #065f46; }
        @media (prefers-color-scheme: dark) {
            .compte.mauvais { background: rgba(127,29,29,.3); border-color: #7f1d1d; color: #fca5a5; }
            .compte.bon { background: rgba(6,95,70,.3); border-color: #065f46; color: #6ee7b7; }
        }
        .compte b { font-weight: 700; }
    </style>
</head>
<body>
    <div class="carte">
        <div class="marque"><span class="marque-point" aria-hidden="true">O</span>{{ config('app.name') }}</div>
        {{--
            ⚠️ Cette page porte son propre texte : l'invité n'est pas forcément connecté,
            il ne verra jamais l'aide contextuelle du panel. Si quelque chose doit être
            expliqué, c'est ici (même principe que resources/views/invitation/refus.blade.php).
        --}}
        @if($cas === 'rejoindre')
            <h1>Invitation à rejoindre « {{ $dossierName }} »</h1>
            <p>Cette invitation concerne l'adresse <strong>{{ $email }}</strong>.</p>
            <p>Si vous avez déjà un compte sur cette instance avec cette adresse, connectez-vous :
                vous reviendrez automatiquement ici pour confirmer que vous rejoignez le dossier.</p>
            <p class="actions">
                <a class="bouton" href="{{ $connexionUrl }}">Se connecter</a>
                @if($inscriptionUrl)
                    <a class="bouton secondaire" href="{{ $inscriptionUrl }}">Créer un compte</a>
                @endif
            </p>
            {{--
                ⚠️ Défaut gênant n°3 du test de bout en bout (2026-10-15) : cette page
                affichait ici « inscription fermée, rendez-vous sur openlmnp.fr » juste
                au-dessus du formulaire de compte gratuit d'indivisaire ci-dessous —
                contradictoire, puisque ce formulaire contourne justement
                `RegistrationGate` et fonctionne TOUJOURS pour une invitation valide. Le
                message n'a donc plus sa place ici : qui n'a pas de compte dispose déjà
                d'un chemin qui marche, juste en dessous.
            --}}

            <hr class="separateur">

            {{--
                ⚠️ Accès gratuit d'indivisaire (lot « dossiers membre », décision du
                2026-10-15) : TOUJOURS proposé, que l'inscription libre soit ouverte ou
                non — c'est précisément son intérêt. L'adresse n'est PAS un champ du
                formulaire : elle est imposée côté serveur par la ligne d'invitation,
                affichée ici en lecture seule pour que ce soit visible. La création
                (ci-dessous) rattache directement (formulaire POST, mot de passe
                saisi = confirmation explicite), au même titre que le cas « confirmer »
                pour qui se connecte avec un compte existant.
            --}}
            <h1 style="font-size:1rem;">Vous n'avez pas encore de compte avec cette adresse ?</h1>
            <p>Créez un accès gratuit d'indivisaire, réservé à ce dossier : aucun abonnement,
                aucune carte bancaire. Vous ne pourrez pas saisir de biens personnels avec ce
                compte — seulement consulter et compléter « {{ $dossierName }} ».</p>
            <form class="membre" method="POST" action="{{ $creerCompteUrl }}">
                @csrf
                <label for="membre-email">E-mail (imposé par l'invitation)</label>
                <input type="email" id="membre-email" value="{{ $email }}" disabled>

                <label for="membre-name">Votre nom</label>
                <input type="text" id="membre-name" name="name" value="{{ old('name') }}" required maxlength="255">
                @error('name') <div class="erreur-champ">{{ $message }}</div> @enderror

                <label for="membre-password">Mot de passe</label>
                <input type="password" id="membre-password" name="password" required>
                @error('password') <div class="erreur-champ">{{ $message }}</div> @enderror

                <label for="membre-password-confirmation">Confirmez le mot de passe</label>
                <input type="password" id="membre-password-confirmation" name="password_confirmation" required>

                <button type="submit">Créer mon accès gratuit d'indivisaire</button>
            </form>
        @elseif($cas === 'confirmer')
            {{--
                Écran 7, variante B — changement de comportement du lot 3 : ici, et
                SEULEMENT ici, un clic EXPLICITE rattache (formulaire POST vers
                `dossier.invitation.confirm`). Le simple fait d'avoir atteint cette page
                (un GET, même répété) n'a RIEN modifié.
            --}}
            <span class="pastille">Dossier d'indivision</span>
            <h1>Rejoindre ce dossier ?</h1>
            <div class="carte-dossier">
                <b>{{ $dossierName }}</b>
                <span>
                    SIREN {{ $siren }}
                    @if($invitedByName)
                        · invité par {{ $invitedByName }}
                    @endif
                </span>
            </div>
            <p>Vous êtes connecté en tant que <strong>{{ $email }}</strong>. Le dossier apparaîtra dans
                votre sélecteur de dossier ; aucune offre n'est consommée sur votre compte.</p>
            <div class="actions">
                <form method="POST" action="{{ $confirmUrl }}">
                    @csrf
                    <button type="submit">Rejoindre le dossier</button>
                </form>
                <form method="POST" action="{{ $declineUrl }}">
                    @csrf
                    <button type="submit" class="secondaire">Refuser</button>
                </form>
            </div>
        @elseif($cas === 'refusee')
            <h1>Invitation refusée</h1>
            <p>Vous ne rejoignez pas ce dossier. Si c'est une erreur, demandez à la personne qui vous a
                invité de vous envoyer un nouveau lien.</p>
            <p>
                <a class="bouton" href="{{ \Filament\Facades\Filament::getUrl() }}">Retour à l'application</a>
            </p>
        @elseif($cas === 'bienvenue')
            <h1>Vous avez rejoint « {{ $dossierName }} »</h1>
            <p>Ce dossier apparaît désormais dans votre sélecteur de dossier, dans l'application.
                Aucune offre n'a été consommée sur votre compte.</p>
            <p>
                <a class="bouton" href="{{ \Filament\Facades\Filament::getUrl() }}">Retour à l'application</a>
            </p>
        @elseif($cas === 'expiree')
            <h1>Cette invitation a expiré</h1>
            <p>Elle n'était valable que jusqu'au
                {{ $date->timezone(config('app.timezone'))->translatedFormat('j F Y \à H\hi') }}.</p>
            <p>Demandez-en une nouvelle à la personne qui vous l'a envoyée : elle peut la relancer en un clic
                depuis « Dossiers liés ».</p>
        @elseif($cas === 'acceptee')
            <h1>Cette invitation a déjà été utilisée</h1>
            <p>Si ce dossier est le vôtre, il apparaît déjà dans votre sélecteur de dossier.</p>
            <p>
                <a class="bouton" href="{{ \Filament\Facades\Filament::getUrl() }}">Retour à l'application</a>
            </p>
        @elseif($cas === 'mauvais-compte')
            {{--
                Écran 7, variante B : UN SEUL bouton, qui déconnecte PUIS ramène sur ce
                même lien (POST vers `dossier.invitation.switch-account`) — jamais un
                simple lien de connexion qui laisserait la mauvaise session active à côté.
            --}}
            <h1>Ce n'est pas le bon compte</h1>
            <p>Cette invitation à rejoindre « {{ $dossierName }} » est réservée à une autre adresse.</p>
            <div class="comptes">
                <div class="compte mauvais"><span>Connecté</span><b>{{ $connecte }}</b></div>
                <div class="compte bon"><span>Invité</span><b>{{ $email }}</b></div>
            </div>
            <p class="discret">Vous reviendrez sur cette page après la connexion : inutile de rouvrir le courriel.</p>
            <form method="POST" action="{{ $switchAccountUrl }}">
                @csrf
                <button type="submit">Me déconnecter et utiliser {{ $email }}</button>
            </form>
        @elseif($cas === 'compte-invalide')
            <h1>Ce compte ne peut pas rejoindre de dossier</h1>
            <p>{{ $raison }}</p>
        @elseif($cas === 'erreur')
            <h1>Ce lien n'est plus valable</h1>
            <p>{{ $message }}</p>
        @else
            <h1>Ce lien n'est plus valable</h1>
            <p>Il a peut-être déjà servi, ou il a été révoqué par la personne qui vous a invité.</p>
            <p>Contactez-la : elle peut vous en envoyer un nouveau depuis « Dossiers liés ».</p>
        @endif
    </div>
</body>
</html>
