<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dernier rappel avant effacement du bac à sable.
 *
 * ⚠️ Cet e-mail AFFIRME quelque chose (« il s'efface dans tant de temps »). Il ne doit donc
 * partir que sur un signal CONNU : la commande qui l'émet exige une date d'expiration
 * renseignée et une adresse consentie, et refuse d'envoyer sur une valeur par défaut.
 *
 * ⚠️ Aucun filet chez le prestataire : un envoi transactionnel ne consulte PAS la liste de
 * désinscription de Brevo, et celui-ci part en SMTP direct. Le consentement repose
 * entièrement sur notre code — `demo_email_consent_at`, horodaté.
 *
 * Contenu volontairement NEUTRE : ce dépôt est public. Le renvoi commercial n'apparaît que
 * si `demo.links.pro` est renseignée, vide par défaut.
 */
class DemoExpiring extends Notification
{
    use Queueable;

    public function __construct(public string $resumeUrl)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiresAt = $notifiable->demo_expires_at?->timezone(config('app.timezone'));

        $mail = (new MailMessage)
            ->to($notifiable->demo_email)
            ->subject('Votre démonstration OpenLMNP s\'efface bientôt')
            ->greeting('Il reste peu de temps')
            ->line($expiresAt
                ? 'Votre bac à sable et tout ce qu\'il contient seront supprimés le '.$expiresAt->translatedFormat('j F \à H\hi').'.'
                : 'Votre bac à sable sera supprimé prochainement.')
            ->line('La suppression est définitive : rien n\'est sauvegardé, et il n\'y a pas de corbeille.')
            ->action('Revoir mon dossier', $this->resumeUrl);

        if (filled(config('demo.links.pro'))) {
            $mail->line('Pour le conserver, vous pouvez le transformer en compte depuis l\'application : vos données sont reprises telles quelles, sans rien ressaisir.');
        }

        return $mail
            ->line('C\'est le dernier message que vous recevrez à cette adresse.')
            ->salutation('L\'équipe OpenLMNP');
    }
}
