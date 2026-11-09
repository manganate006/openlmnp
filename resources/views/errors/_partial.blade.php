<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Erreur' }} — {{ config('app.name') }}</title>
    {{--
        Page d'erreur AUTONOME (403/404/405/419/500) : elle doit pouvoir s'afficher
        même quand le panel Filament n'a pas fini de démarrer (hôte refusé, route
        inconnue...), donc pas de dépendance à `filament/partials/theme-tokens` —
        mêmes littéraux de palette que `resources/views/dossier-invitation/show.blade.php`
        et `resources/views/invitation/refus.blade.php`.
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
            max-width: 30rem; width: 100%; background: #ffffff; border: 1px solid #a7f3d0;
            border-radius: .75rem; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); text-align: center;
        }
        @media (prefers-color-scheme: dark) { .carte { background: #1e293b; border-color: #065f46; } }
        .code { font-size: .8rem; font-weight: 700; letter-spacing: .05em; color: #059669; margin: 0 0 .5rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { line-height: 1.6; margin: 0 0 1.25rem; }
        a.bouton {
            display: inline-block; padding: .55rem 1.1rem; border-radius: .5rem;
            background: #059669; color: #ffffff; text-decoration: none; font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="carte">
        <p class="code">{{ $code }}</p>
        <h1>{{ $heading }}</h1>
        <p>{{ $message }}</p>
        <a class="bouton" href="{{ $backUrl }}">{{ $backLabel ?? 'Retour à l\'accueil' }}</a>
    </div>
</body>
</html>
