<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email de bienvenue envoyé quand un compte est créé pour l'utilisateur
 * (API de provisioning) : invite à définir son mot de passe.
 */
class WelcomeSetPassword extends Notification
{
    use Queueable;

    public function __construct(public string $url)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Votre compte OpenLMNP a été créé')
            ->greeting('Bienvenue sur OpenLMNP !')
            ->line('Un compte a été créé pour l\'adresse '.$notifiable->email.'.')
            ->line('Cliquez sur le bouton ci-dessous pour définir votre mot de passe et accéder à votre espace.')
            ->action('Définir mon mot de passe', $this->url)
            ->line('Ce lien expire au bout de 60 minutes. Passé ce délai, utilisez « Mot de passe oublié » sur la page de connexion.')
            ->salutation('L\'équipe OpenLMNP');
    }
}
