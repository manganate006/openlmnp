<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <meta name="robots" content="noindex, nofollow">
    <title>Invitation — {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #f8fafc; color: #0f172a; padding: 1.5rem;
        }
        @media (prefers-color-scheme: dark) { body { background: #0f172a; color: #e2e8f0; } }
        .carte {
            max-width: 32rem; width: 100%; background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: .75rem; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,.08);
        }
        @media (prefers-color-scheme: dark) { .carte { background: #1e293b; border-color: #334155; } }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { line-height: 1.6; margin: 0 0 1rem; }
        a.bouton {
            display: inline-block; padding: .55rem 1.1rem; border-radius: .5rem;
            background: #059669; color: #ffffff; text-decoration: none; font-weight: 600;
        }
        a.lien { color: #059669; }
        .discret { font-size: .875rem; opacity: .75; }
    </style>
</head>
<body>
    <div class="carte">
        {{--
            ⚠️ Cette page porte son propre texte : l'invité n'est pas connecté, il ne verra
            jamais l'aide contextuelle du panel. Si quelque chose doit être expliqué, c'est ici.
        --}}
        @if($cas === 'expiree')
            <h1>Cette invitation a expiré</h1>
            <p>Elle n'était valable que jusqu'au
                {{ $date->timezone(config('app.timezone'))->translatedFormat('j F Y \à H\hi') }}.</p>
            <p>Demandez-en une nouvelle à la personne qui vous l'a envoyée : elle peut la relancer en un clic.</p>
        @elseif($cas === 'acceptee')
            <h1>Un compte existe déjà</h1>
            <p>Cette invitation a déjà servi. Si ce compte est le vôtre, connectez-vous.</p>
            <p>
                <a class="bouton" href="{{ \Filament\Facades\Filament::getLoginUrl() }}">Se connecter</a>
            </p>
            <p class="discret">Mot de passe oublié&nbsp;? Le lien de réinitialisation se trouve sur la page de connexion.</p>
        @elseif($cas === 'deja-connecte')
            <h1>Vous êtes déjà connecté</h1>
            <p>Vous utilisez actuellement le compte <strong>{{ $connecte }}</strong>, qui n'est pas celui
                de cette invitation.</p>
            <p>Déconnectez-vous, puis rouvrez le lien reçu par courriel.</p>
            <p>
                <a class="lien" href="{{ \Filament\Facades\Filament::getUrl() }}">Retour à l'application</a>
            </p>
        @else
            <h1>Ce lien n'est plus valable</h1>
            <p>Il a peut-être déjà servi, ou il a été remplacé par un lien plus récent.</p>
            <p>Contactez la personne qui vous a invité : elle peut vous en envoyer un nouveau.</p>
        @endif
    </div>
</body>
</html>
