<?php

namespace App\Notifications;

use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Transmet un retour d'utilisateur à l'adresse configurée dans
 * `config('feedback.forward_email')`.
 *
 * N'est envoyée QUE si cette adresse est renseignée : une instance auto-hébergée la
 * laisse vide et rien ne sort alors de la machine (l'utilisateur se voit proposer un
 * `mailto:` prérempli, qu'il envoie lui-même s'il le souhaite).
 */
class FeedbackReceived extends Notification
{
    use Queueable;

    public function __construct(public Feedback $feedback)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $feedback = $this->feedback;
        $mood = $feedback->isPositive() ? 'positif' : 'négatif';

        $mail = (new MailMessage)
            ->subject('OpenLMNP — retour '.$mood.($feedback->audience === Feedback::AUDIENCE_DEMO ? ' (démonstration)' : ''))
            ->greeting('Nouveau retour '.$mood);

        if (filled($feedback->message)) {
            $mail->line('> '.str_replace("\n", "\n> ", $feedback->message));
        } else {
            $mail->line('_Aucun message : l\'utilisateur a seulement répondu à la question._');
        }

        $mail->line('---');

        if (filled($feedback->author_name)) {
            $mail->line('**Prénom** : '.$feedback->author_name);
        }

        if (filled($feedback->author_email)) {
            $mail->line('**E-mail** : '.$feedback->author_email);
        }

        $mail->line('**Origine** : '.($feedback->audience === Feedback::AUDIENCE_DEMO
            ? 'démonstration (données fictives)'
            : 'compte utilisateur'));

        $mail->line('**Publication autorisée** : '.($feedback->can_publish ? 'oui' : 'non'));

        // Le garde-fou porté par le message lui-même : un tri fait trois mois plus tard
        // n'aura plus le contexte en tête, et publier un « témoignage » écrit après dix
        // minutes de démonstration serait un faux avis au sens du Code de la consommation.
        if ($feedback->can_publish && $feedback->audience === Feedback::AUDIENCE_DEMO) {
            $mail->line('⚠️ Retour émis depuis la **démonstration** : son auteur n\'a pas tenu sa propre comptabilité dans le logiciel. Utile au produit, mais **non publiable comme témoignage d\'usage**.');
        }

        return $mail->salutation('— OpenLMNP');
    }
}
