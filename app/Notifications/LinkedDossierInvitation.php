<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Invitation à rejoindre un dossier lié EXISTANT (second indivisaire, lot 3).
 *
 * ⚠️ Distincte de App\Notifications\AccountInvitation : celle-ci ne crée JAMAIS
 * de compte - elle rattache un principal (déjà abonné, ou qui vient de se
 * connecter/s'inscrire) à un dossier déjà en place, sans consommer son quota.
 * Même patron de lien (jeton en SEGMENT d'URL, jamais en requête - voir
 * App\Http\Controllers\Dossier\AcceptDossierInvitationController) et même
 * piège GTM évité (route autonome hors panel, voir routes/web.php).
 *
 * ⚠️ Thème de courriel aux couleurs de la marque (écran 7, variante B, décision du
 * 2026-10-15) : `->theme('openlmnp')` vise `resources/views/vendor/mail/html/
 * themes/openlmnp.css`, une COPIE du thème markdown par défaut de Laravel avec le
 * bouton et l'en-tête recolorés en émeraude - jamais le thème `default`, pour ne
 * pas changer l'apparence des AUTRES e-mails de l'app (réinitialisation de mot de
 * passe, 2FA...) qui n'ont pas été revus dans ce lot.
 */
class LinkedDossierInvitation extends Notification
{
    use Queueable;

    public function __construct(
        public string $url,
        public CarbonInterface $expiresAt,
        public ?string $invitedByName,
        public string $dossierName,
        public string $siren,
    ) {}

    /**
     * Fabrique le lien. Même raisonnement que AccountInvitation::urlFor() :
     * `forceRootUrl()`/`forceScheme()` pour un envoi hors requête HTTP (file
     * d'attente synchrone déclenchée depuis une action Livewire, mais la même
     * notification peut aussi partir d'une commande future), pas de signature
     * d'URL (le secret EST le jeton, la révocation se vérifie en base).
     */
    public static function urlFor(string $plainToken): string
    {
        $base = (string) config('app.url');
        URL::forceRootUrl($base);
        URL::forceScheme(str_starts_with($base, 'https://') ? 'https' : 'http');

        return route('dossier.invitation.show', ['token' => $plainToken]);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * ⚠️ JAMAIS de `->to()` ici - voir App\Notifications\AccountInvitation pour
     * l'incident que ça a causé. Le destinataire se choisit dans
     * App\Models\LinkedDossierInvitation::routeNotificationForMail().
     */
    public function toMail(object $notifiable): MailMessage
    {
        $quand = $this->expiresAt
            ->copy()
            ->timezone(config('app.timezone'))
            ->translatedFormat('j F Y \à H\hi');

        $qui = filled($this->invitedByName) ? $this->invitedByName : 'Un utilisateur';

        // `->line()`/`->action()` continuent de peupler `introLines`/`outroLines`/
        // `actionText`/`actionUrl` (hérités de SimpleMessage) : le gabarit markdown
        // personnalisé ci-dessous les reparcourt À L'IDENTIQUE du gabarit par défaut
        // de Laravel, il ne fait qu'y INSÉRER l'encadré (dossier, SIREN, expéditeur).
        // Les tests qui lisent `$mail->introLines`/`outroLines`/`actionUrl`
        // directement restent donc valables sans y toucher.
        return (new MailMessage)
            ->subject($qui.' vous invite à rejoindre « '.$this->dossierName.' » sur '.config('app.name'))
            ->greeting('Vous êtes invité à rejoindre un dossier')
            ->line($qui.' vous invite à rejoindre le dossier « '.$this->dossierName.' » sur '.config('app.name').'.')
            ->line('Si vous avez déjà un compte sur cette instance, connectez-vous avec la même adresse '
                .'électronique que celle qui a reçu ce courriel : le lien vous rattachera au dossier. '
                .'Si vous n\'avez pas encore de compte, le lien vous expliquera comment en créer un.')
            ->action('Rejoindre le dossier', $this->url)
            ->line('Ce lien est personnel et ne sert qu\'une seule fois. Il expire le '.$quand
                .'. Passé ce délai, demandez-en un nouveau à la personne qui vous a invité.')
            ->line('Si vous n\'attendiez pas cette invitation, ignorez ce message : aucun accès n\'est accordé sans vous.')
            ->salutation('L\'équipe '.config('app.name'))
            ->markdown('mail.linked-dossier-invitation', [
                'dossierName' => $this->dossierName,
                'siren' => $this->siren,
                'invitedByName' => $qui,
            ])
            ->theme('openlmnp');
    }
}
