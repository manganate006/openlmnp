<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Lien de reprise du bac à sable, envoyé à l'adresse laissée pour la prolongation.
 *
 * Il existe parce qu'on ne pouvait PAS revenir sur sa démonstration : le cookie de session
 * dure `SESSION_LIFETIME` (120 min) alors que le sandbox vit 24 h, et le compte porte un mot
 * de passe aléatoire de 40 caractères que personne ne connaît. Qui revenait trois heures plus
 * tard trouvait un bac à sable bien vivant, et définitivement inaccessible.
 *
 * ⚠️ Contenu volontairement NEUTRE : ce dépôt est public, il ne porte ni tarif ni
 * argumentaire. Le bloc commercial n'apparaît que si `demo.links.pro` est renseignée —
 * vide par défaut, donc invisible en auto-hébergé. Même patron que `feedback.links.pro`.
 */
class DemoResumeLink extends Notification
{
    use Queueable;

    public function __construct(public string $url)
    {
    }

    /**
     * Fabrique le lien signé.
     *
     * ⚠️ Hors requête HTTP (la commande planifiée), `URL::forceRootUrl()` est indispensable :
     * sans lui le lien est bâti sur l'hôte interne du conteneur et arrive cassé.
     *
     * Mais `forceRootUrl()` ne fixe que l'HÔTE — le schéma, lui, est repris de la requête
     * courante et vient donc écraser celui de `app.url`. Un lien émis derrière un proxy mal
     * configuré sortirait en `http://`. D'où le `forceScheme()` explicite : la garantie ne
     * doit pas reposer sur `TrustProxies`.
     *
     * L'expiration du lien est calée sur celle du bac à sable : un lien qui survivrait à la
     * purge du compte ne mènerait nulle part, et un lien plus court priverait quelqu'un de
     * son sandbox encore vivant.
     */
    public static function urlFor(User $user): string
    {
        $base = (string) config('app.url');

        URL::forceRootUrl($base);
        URL::forceScheme(str_starts_with($base, 'https://') ? 'https' : 'http');

        return URL::temporarySignedRoute('demo.resume', $user->demo_expires_at, ['user' => $user->id]);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * L'adresse de destination est `demo_email`, pas `email` : le compte de démonstration
     * porte une adresse technique en `@demo.local`, qui n'existe pas.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $expiresAt = $notifiable->demo_expires_at?->timezone(config('app.timezone'));

        $mail = (new MailMessage)
            ->to($notifiable->demo_email)
            ->subject('Votre démonstration OpenLMNP vous attend')
            ->greeting('Votre bac à sable est prolongé')
            ->line($expiresAt
                ? 'Il vous attend jusqu\'au '.$expiresAt->translatedFormat('j F Y \à H\hi').'.'
                : 'Il vous attend.')
            ->action('Revenir à mon dossier', $this->url)
            ->line('Ce lien vous reconnecte directement, depuis n\'importe quel appareil et sans mot de passe. Traitez-le comme une clé : qui l\'a, y entre.');

        if (filled(config('demo.links.pro'))) {
            $mail->line('Pour conserver ce dossier au-delà de la démonstration, vous pouvez le transformer en compte à tout moment depuis l\'application.');
        }

        return $mail
            ->line('Vous recevrez un dernier rappel avant l\'effacement, puis plus rien : cette adresse ne sert qu\'à ces deux envois.')
            ->salutation('L\'équipe OpenLMNP');
    }
}
