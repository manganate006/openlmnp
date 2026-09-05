<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\DemoExpiring;
use App\Notifications\DemoResumeLink;
use App\Support\DemoExpiry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Prévient par courriel les bacs à sable qui approchent de l'effacement.
 *
 * N'écrit qu'aux comptes ayant LAISSÉ une adresse et donné leur consentement : la
 * prolongation est le seul point de capture du parcours, et il n'y en a pas d'autre.
 *
 * Planifiée juste AVANT `openlmnp:demo-cleanup` : dans l'ordre inverse, la purge
 * supprimerait le compte avant que le rappel ne parte.
 */
class DemoExpiryNotifyCommand extends Command
{
    protected $signature = 'openlmnp:demo-expiry-notify {--hours=24 : Seuil de rappel, en heures restantes}';

    protected $description = 'Envoie le rappel avant effacement aux bacs à sable prolongés';

    /** Marqueur écrit dans `demo_reminders_seen` pour ne pas réécrire deux fois. */
    private const SENT_MARKER = -1;

    public function handle(): int
    {
        if (! config('demo.enabled')) {
            $this->info('Mode démonstration désactivé : rien à faire.');

            return self::SUCCESS;
        }

        $threshold = (int) $this->option('hours');
        $now = Carbon::now();
        $sent = 0;

        User::query()
            ->where('is_demo', true)
            ->whereNotNull('demo_email')
            ->whereNotNull('demo_email_consent_at')
            // ⚠️ Un e-mail qui AFFIRME exige un signal CONNU. La condition est écrite en
            // POSITIF — date présente ET fenêtre franchie — plutôt qu'en négation d'un
            // défaut : sur une date nulle, rien ne doit partir. Le pendant de ce piège a
            // été payé côté vitrine, où `has_loan ?? false` aurait annoncé à tous les
            // emprunteurs qu'ils n'avaient pas d'emprunt.
            ->whereNotNull('demo_expires_at')
            ->where('demo_expires_at', '>', $now)
            ->where('demo_expires_at', '<=', $now->copy()->addHours($threshold))
            ->each(function (User $user) use (&$sent, $now) {
                $seen = array_map('intval', $user->demo_reminders_seen ?? []);

                if (in_array(self::SENT_MARKER, $seen, true)) {
                    return;
                }

                // Secondes gardes, redondantes avec la requête. Elles existent parce que la
                // commande peut être relancée à la main sur un jeu de données incohérent, et
                // parce qu'un envoi transactionnel n'a AUCUN filet en aval : ni Brevo ni le
                // relais SMTP ne rattraperont un message qui n'aurait pas dû partir.
                //
                // ⚠️ L'adresse est vérifiée SÉPARÉMENT : `applies()` ne la regarde pas, et
                // `DemoExpiring` la passe à `->to()`. Sur un `demo_email` nul, le message
                // partirait vers l'adresse technique `@demo.local` du compte — inexistante.
                if (blank($user->demo_email) || blank($user->demo_email_consent_at)) {
                    return;
                }

                if (! DemoExpiry::for($user, $now)->applies()) {
                    return;
                }

                try {
                    $user->notify(new DemoExpiring(DemoResumeLink::urlFor($user)));
                } catch (\Throwable $e) {
                    Log::error('Rappel d\'expiration de démonstration : échec d\'envoi', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);

                    return;
                }

                $seen[] = self::SENT_MARKER;
                $user->forceFill(['demo_reminders_seen' => $seen])->save();
                $sent++;
            });

        $this->info("Rappels envoyés : {$sent}");

        return self::SUCCESS;
    }
}
