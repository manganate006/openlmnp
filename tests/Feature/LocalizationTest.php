<?php

use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;

// L'UI et les emails sont en français, mais le répertoire lang/ n'a longtemps contenu
// que les traductions Filament (lang/vendor/filament-*). Tout ce qui vient du CŒUR de
// Laravel — passwords.*, auth.*, validation.*, pagination.* et les clés JSON des vues
// de mail — retombait donc sur le fallback anglais embarqué dans vendor/, d'où l'écran
// bilingue signalé sur /password-reset/request :
//
//   titre : « We have emailed your password reset link. »   <- __('passwords.sent'), cœur Laravel
//   corps : « Si votre compte n'existe pas, … »             <- clé filament-panels::, traduite
//
// (composition faite par Filament\Auth\Pages\PasswordReset\RequestPasswordReset::getSentNotification)
//
// Ces tests verrouillent la présence ET la cohérence des fichiers lang/fr.

// === CLÉS DU CŒUR LARAVEL ===

it('runs in French by default', function () {
    expect(app()->getLocale())->toBe('fr');
});

it('translates the password broker statuses', function () {
    // 'passwords.sent' est exactement le titre du toast rapporté en anglais.
    expect(__('passwords.sent'))->toBe('Le lien de réinitialisation vous a été envoyé par email.')
        ->and(__('passwords.reset'))->toBe('Votre mot de passe a été réinitialisé.')
        ->and(__('passwords.token'))->not->toContain('token')
        ->and(__('passwords.throttled'))->not->toBe('Please wait before retrying.')
        ->and(__('passwords.user'))->not->toContain('user');
});

it('translates the authentication and pagination lines', function () {
    expect(__('auth.failed'))->not->toContain('credentials')
        ->and(__('auth.throttle', ['seconds' => 42]))->toContain('42')
        ->and(__('auth.throttle', ['seconds' => 42]))->not->toContain('Too many')
        ->and(__('pagination.previous'))->toBe('&laquo; Précédent')
        ->and(__('pagination.next'))->toBe('Suivant &raquo;');
});

it('translates the validation messages used across Filament forms', function () {
    expect(__('validation.required', ['attribute' => 'adresse']))->toBe('Le champ adresse est obligatoire.')
        ->and(__('validation.email', ['attribute' => 'email']))->toContain('adresse email valide')
        ->and(__('validation.min.string', ['attribute' => 'mot de passe', 'min' => 8]))
        ->toBe('Le champ mot de passe doit contenir au moins 8 caractères.');
});

// === CLÉS JSON DES VUES DE MAIL ===
//
// Illuminate\Auth\Notifications\ResetPassword et les vues notifications::email /
// mail::html.message passent par Lang::get('<phrase anglaise entière>') : ces clés
// n'existent que dans lang/fr.json. Sans le fichier, la clé EST le texte anglais et
// rien n'échoue — l'email part simplement en anglais, en silence.

it('translates the JSON keys of the mail layout', function () {
    expect(__('Hello!'))->toBe('Bonjour !')
        ->and(__('Regards,'))->toBe('Cordialement,')
        ->and(__('All rights reserved.'))->toBe('Tous droits réservés.')
        ->and(__('Reset Password'))->toBe('Réinitialiser mon mot de passe');
});

it('keeps the subcopy key byte-for-byte identical to the mail view', function () {
    // La clé contient un saut de ligne littéral au milieu et des guillemets échappés
    // (voir Illuminate/Notifications/resources/views/email.blade.php). À un caractère
    // près, la correspondance échoue sans erreur et l'anglais réapparaît.
    $key = "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n"
        .'into your web browser:';

    expect(__($key))->not->toBe($key)
        ->and(__($key, ['actionText' => 'Se connecter']))->toContain('Se connecter');
});

// === RENDU RÉEL DE L'EMAIL DE RÉINITIALISATION ===

it('sends the password reset email fully in French', function () {
    $user = User::factory()->create();

    $notification = new ResetPassword('jeton-de-test');
    $notification->url = 'https://app.openlmnp.fr/password-reset/reset?token=jeton-de-test';

    $mail = $notification->toMail($user);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Réinitialisation de votre mot de passe');

    foreach (['Bonjour !', 'Réinitialiser mon mot de passe', 'Cordialement,', 'Tous droits réservés.'] as $expected) {
        expect($html)->toContain($expected);
    }

    // :count doit être substitué par auth.passwords.users.expire, pas rester littéral.
    expect($html)->toContain('60 minutes')
        ->and($html)->not->toContain(':count');

    foreach (['Hello!', 'Reset Password', 'Regards,', 'All rights reserved.', 'no further action is required'] as $english) {
        expect($html)->not->toContain($english);
    }
});

// === GARDE-FOUS DE COHÉRENCE ===

it('mirrors every core validation key with identical placeholders', function () {
    // Seul vrai risque de régression du chantier : une traduction qui perd un :marqueur
    // affiche un message tronqué (« Le champ doit contenir au moins caractères. »).
    $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    $fr = require base_path('lang/fr/validation.php');

    $placeholders = function (string $line): array {
        preg_match_all('/:[a-zA-Z_]+/', $line, $matches);
        $found = array_values(array_unique($matches[0]));
        sort($found);

        return $found;
    };

    $problems = [];

    $walk = function (array $en, array $fr, string $prefix = '') use (&$walk, $placeholders, &$problems): void {
        foreach ($en as $key => $line) {
            $path = $prefix === '' ? $key : "{$prefix}.{$key}";

            // Squelettes d'exemple, non traduisibles.
            if (in_array($path, ['custom', 'attributes'], true)) {
                continue;
            }

            if (! array_key_exists($key, $fr)) {
                $problems[] = "{$path} : absente de lang/fr/validation.php";

                continue;
            }

            if (is_array($line)) {
                $walk($line, $fr[$key], $path);

                continue;
            }

            if ($placeholders($line) !== $placeholders($fr[$key])) {
                $problems[] = "{$path} : marqueurs divergents";
            }

            if ($line === $fr[$key]) {
                $problems[] = "{$path} : encore en anglais";
            }
        }
    };

    $walk($en, $fr);

    expect($problems)->toBe([]);
});

it('keeps the same placeholders on both sides of every JSON translation', function () {
    $translations = json_decode(file_get_contents(base_path('lang/fr.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($translations)->not->toBeEmpty();

    foreach ($translations as $key => $value) {
        preg_match_all('/:[a-zA-Z_]+/', $key, $inKey);
        preg_match_all('/:[a-zA-Z_]+/', $value, $inValue);

        expect(array_values(array_unique($inValue[0])))
            ->toEqualCanonicalizing(array_values(array_unique($inKey[0])), "clé JSON « {$key} »");
    }
});

it('ships French defaults to self-hosted installations', function () {
    // composer setup copie .env.example : une installation neuve doit démarrer en
    // français, pas en anglais intégral.
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain('APP_LOCALE=fr')
        ->and($example)->toContain('APP_FALLBACK_LOCALE=fr')
        ->and($example)->not->toContain('APP_NAME=Laravel');
});

it('propagates the locale variables through the Docker entrypoint', function () {
    // PIÈGE N°1 du déploiement : l'entrypoint ne recopie vers .env qu'une allowlist
    // fixe. Une variable absente de la boucle est ignorée en silence en production,
    // `docker run -e` ou pas.
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    preg_match('/for var in (.*?); do/s', $entrypoint, $matches);

    expect($matches)->not->toBeEmpty();

    $allowlist = preg_split('/\s+/', str_replace('\\', ' ', $matches[1]), -1, PREG_SPLIT_NO_EMPTY);

    expect($allowlist)->toContain('APP_LOCALE')
        ->and($allowlist)->toContain('APP_FALLBACK_LOCALE');
});
