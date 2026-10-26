<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <title>{{ $title ?? config('app.name') }}</title>
</head>
<body>
{{--
    Gabarit de repli de Livewire pour un composant « page » qui n'a pas eu l'occasion de
    poser son propre agencement (`#[Layout]`/`->layout()`) — notamment un composant
    Filament qui redirige dès `mount()` (ex. la page de connexion, déjà authentifié) :
    le rendu passe alors par `config('livewire.component_layout')` (= `layouts::app`)
    AVANT que la redirection ne soit suivie par le navigateur.

    Avant sa création, ce fichier n'existait pas : Livewire n'enregistre l'espace de
    noms `layouts` auprès de la Factory de vues QUE si `resources/views/layouts/`
    existe (`LivewireServiceProvider::bootConfig()`, `if (! is_dir($location)) continue;`)
    — sans lui, toute tentative de résolution de `layouts::app` levait
    « No hint path defined for [layouts]. » (500), journalisé alors que l'utilisateur
    ne voyait qu'un écran blanc ou une redirection manquée. Le contenu de cette page
    n'est quasiment jamais visible (la redirection est suivie aussitôt) : il reste
    volontairement minimal, repris du gabarit que `php artisan livewire:layout` publie
    par défaut.
--}}
{{ $slot }}
</body>
</html>
