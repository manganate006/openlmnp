<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Invitation à créer un compte sur cette instance.
 *
 * ⚠️ Nom NEUTRE, pas « BetaInvitation » : ce dépôt est public et la fonctionnalité qui y vit
 * est « inscription sur invitation », pas « bêta ». Une instance auto-hébergée s'en sert pour
 * ouvrir son instance à sa famille ou à son comptable.
 */
class AccountInvitation extends Notification
{
    use Queueable;

    public function __construct(
        public string $url,
        public CarbonInterface $expiresAt,
        public ?string $invitedByName = null,
    ) {
    }

    /**
     * Fabrique le lien.
     *
     * ⚠️ Hors requête HTTP (commande artisan), `URL::forceRootUrl()` est indispensable : sans
     * lui le lien est bâti sur l'hôte interne du conteneur et arrive cassé. Et `forceRootUrl()`
     * ne fixe que l'HÔTE - le schéma est repris de la requête courante, donc un lien émis
     * derrière un proxy sortirait en `http://`. Même patron que `DemoResumeLink`.
     *
     * ⚠️ Pas de signature d'URL, contrairement à `demo.resume` : là-bas le payload est un
     * `user->id` devinable, ici le SECRET EST dans l'URL. Une signature ajouterait une
     * dépendance à APP_KEY sans rien apporter - la révocation se vérifie en base, pas dans le
     * jeton.
     */
    public static function urlFor(string $plainToken): string
    {
        $base = (string) config('app.url');
        URL::forceRootUrl($base);
        URL::forceScheme(str_starts_with($base, 'https://') ? 'https' : 'http');

        return route('invitation.show', ['token' => $plainToken]);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * ⚠️ JAMAIS de `->to()` ici. `MailMessage` n'a pas cette méthode (c'est `Mailable`), et
     * l'appeler lève une `Error` AU MOMENT DE L'ENVOI - donc après que la ligne a été écrite
     * en base. Incident de production du 2026-09-06, rejouable à l'identique ici. Le
     * destinataire se choisit dans `Invitation::routeNotificationForMail()`.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $quand = $this->expiresAt
            ->copy()
            ->timezone(config('app.timezone'))
            ->translatedFormat('j F Y \à H\hi');

        $message = (new MailMessage)
            ->subject('Votre accès à ' . config('app.name'))
            ->greeting('Une invitation vous attend');

        if (filled($this->invitedByName)) {
            $message->line($this->invitedByName . ' vous invite à créer votre compte sur ' . config('app.name') . '.');
        } else {
            $message->line('Vous êtes invité à créer votre compte sur ' . config('app.name') . '.');
        }

        return $message
            ->action('Créer mon compte', $this->url)
            ->line('Ce lien est personnel et ne sert qu\'une seule fois. Il expire le ' . $quand
                . '. Passé ce délai, demandez-en un nouveau à la personne qui vous a invité.')
            ->line('Vous choisirez votre mot de passe en ouvrant ce lien. Personne d\'autre ne le connaîtra, '
                . 'pas même l\'administrateur de cette instance.')
            ->line('Si vous n\'attendiez pas cette invitation, ignorez ce message : sans ce lien, aucun compte n\'est créé.')
            ->salutation('L\'équipe ' . config('app.name'));
    }
}
