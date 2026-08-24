<?php

namespace App\Providers;

use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Dates : saisie ET affichage au format français partout (issue #6).
        //
        // ⚠️ `DatePicker` est NATIF par défaut (`CanBeNative::$isNative = true`) : il rend un
        // `<input type="date">` qui IGNORE `displayFormat()` et suit la locale du navigateur.
        // Les 16 champs de l'app déclaraient bien `->displayFormat('d/m/Y')` sans aucun effet :
        // la saisie partait en ISO alors que les colonnes des tables affichaient `d/m/Y`.
        // `native(false)` bascule sur le sélecteur JS de Filament, qui l'honore.
        // Configuré ici une fois pour toutes : un `->native(false)` par champ se réoublierait
        // au prochain formulaire. Une chaîne locale peut toujours surcharger ces valeurs.
        DatePicker::configureUsing(fn (DatePicker $picker) => $picker
            ->native(false)
            ->displayFormat('d/m/Y')
            ->firstDayOfWeek(1)
            ->closeOnDateSelection());

        // First registered user automatically becomes admin
        User::creating(function (User $user) {
            if (DB::table('users')->count() === 0) {
                $user->is_admin = true;
            }
        });

        // Queue analytics events (GA4 via GTM) for the next rendered page.
        // The queue survives the post-auth redirect and is consumed by
        // partials/gtm-head, which is only rendered when GTM is configured.
        Event::listen(Registered::class, function () {
            self::queueAnalyticsEvent([
                'event' => 'sign_up',
                'method' => 'email',
                // Drapeau posé par la route /demo : mesure la conversion
                // démo → inscription (le compte sandbox est un autre user).
                'from_demo' => request()->hasCookie('olmnp_demo_seen'),
            ]);
        });
        Event::listen(Login::class, function (Login $event) {
            self::queueAnalyticsEvent([
                'event' => 'login',
                'method' => ($event->user->is_demo ?? false) ? 'demo' : 'email',
            ]);
        });

        // Allow private disk to generate temporary signed URLs for file previews
        Storage::disk('local')->buildTemporaryUrlsUsing(
            fn (string $path, \DateTimeInterface $expiration) => URL::temporarySignedRoute(
                'documents.show',
                $expiration,
                ['path' => $path],
            ),
        );
    }

    /**
     * Empile un événement analytics dans la session (flash) sans écraser
     * ceux déjà en attente — inscription + connexion arrivent dans la même requête.
     */
    public static function queueAnalyticsEvent(array $payload): void
    {
        $events = (array) session()->get('analytics', []);
        $events[] = $payload;
        session()->flash('analytics', $events);
    }
}
