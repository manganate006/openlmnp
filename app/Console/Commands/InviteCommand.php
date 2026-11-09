<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Services\InvitationService;
use App\Support\RegistrationGate;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Invite une adresse à créer son compte.
 *
 * ⚠️ Le lien est IMPRIMÉ dans tous les cas, y compris quand le courriel part. C'est le filet
 * des instances sans SMTP — même raison d'être que `openlmnp:reset-password`, qui imprime le
 * lien Filament plutôt que de compter sur un envoi.
 */
class InviteCommand extends Command
{
    protected $signature = 'openlmnp:invite
        {email? : Adresse à inviter}
        {--name= : Nom pré-rempli sur le formulaire d\'inscription}
        {--days= : Durée de validité en jours (défaut : INVITATION_TTL_DAYS)}
        {--note= : Note interne, jamais montrée à l\'invité}
        {--by= : Adresse de l\'administrateur émetteur (défaut : le premier admin)}
        {--resend : Régénère le jeton de l\'invitation en cours et la renvoie}
        {--revoke : Révoque l\'invitation en cours pour cette adresse}
        {--list : Liste les invitations et leur état}
        {--no-mail : N\'envoie aucun courriel, imprime seulement le lien}';

    protected $description = 'Invite une adresse à créer son compte sur cette instance';

    public function handle(InvitationService $invitations): int
    {
        if ($this->option('list')) {
            return $this->lister();
        }

        $email = $this->argument('email');

        if (blank($email)) {
            $this->error('Indiquez une adresse, ou utilisez --list.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim($email));

        $this->avertissements();

        try {
            if ($this->option('revoke')) {
                $invitation = $this->invitationEnCours($email);
                $invitations->revoke($invitation);
                $this->info("Invitation révoquée pour {$email}. Le lien ne fonctionne plus.");
                $this->line('  ⚠️  Révoquer ne suspend AUCUN compte : si un compte existe déjà,');
                $this->line('      c\'est l\'API de provisionnement qui le suspend.');

                return self::SUCCESS;
            }

            if ($this->option('resend')) {
                $invitation = $this->invitationEnCours($email);
                $plain = $invitations->resend($invitation, $this->jours());
                $this->line('  ⚠️  Le lien précédent ne fonctionne plus : relancer, c\'est remplacer.');
            } else {
                [$invitation, $plain] = $invitations->create(
                    $email,
                    $this->option('name'),
                    $this->emetteur(),
                    $this->jours(),
                    $this->option('note'),
                );
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $envoye = false;

        if (! $this->option('no-mail')) {
            $envoye = $invitations->notify($invitation->refresh(), $plain);
        }

        $quand = $invitation->expires_at->timezone(config('app.timezone'))->translatedFormat('j F Y \à H\hi');

        $this->newLine();
        $this->info("Invitation pour {$email} — valable jusqu'au {$quand}.");
        $this->newLine();
        $this->line('Lien d\'invitation, à usage unique :');
        $this->line('  ' . AccountInvitation::urlFor($plain));
        $this->newLine();
        $this->line('  ⚠️  Ce lien ne sera plus jamais affiché : seule son empreinte est en base.');
        $this->line("      Pour en obtenir un nouveau : openlmnp:invite {$email} --resend");

        if (! $envoye && ! $this->option('no-mail')) {
            $this->newLine();
            $this->line('  ⚠️  Aucun courriel n\'est parti (MAIL_MAILER=' . config('mail.default') . ').');
            $this->line('      Transmettez le lien vous-même.');
        }

        return self::SUCCESS;
    }

    /** Garde-fous IMPRIMÉS, jamais silencieux : c'est le genre de piège que ce dépôt traque. */
    private function avertissements(): void
    {
        $mode = config('app.allow_registration', 'auto');

        if ($mode !== RegistrationGate::MODE_INVITE) {
            $this->newLine();
            $this->line("  ⚠️  ALLOW_REGISTRATION vaut « {$mode} », pas « invite ».");

            if (! RegistrationGate::exposesRoute()) {
                $this->line('      La page d\'inscription n\'existe pas sur cette instance : le lien mènera');
                $this->line('      à une erreur. Passez la variable à « invite ».');
            } else {
                $this->line('      L\'inscription est ouverte À TOUS, l\'invitation n\'est donc pas');
                $this->line('      nécessaire pour créer un compte.');
            }
        }

        if (User::query()->count() === 0) {
            $this->newLine();
            $this->line('  ⚠️  Aucun compte n\'existe encore : le compte créé par cette invitation');
            $this->line('      deviendra automatiquement ADMINISTRATEUR de l\'instance.');
        }
    }

    private function lister(): int
    {
        $lignes = Invitation::query()
            ->with(['invitedBy', 'user'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invitation $i) => [
                $i->id,
                $i->email,
                $i->status()->label(),
                $i->expires_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                $i->sent_count,
                $i->invitedBy?->email ?? '—',
                $i->user?->email ?? '—',
            ])
            ->all();

        if ($lignes === []) {
            $this->info('Aucune invitation.');

            return self::SUCCESS;
        }

        $this->table(['#', 'Adresse', 'État', 'Expire le', 'Envois', 'Invitée par', 'Compte'], $lignes);

        return self::SUCCESS;
    }

    private function invitationEnCours(string $email): Invitation
    {
        $invitation = Invitation::query()->forEmail($email)->orderByDesc('created_at')->first();

        if ($invitation === null) {
            throw new RuntimeException("Aucune invitation pour {$email}.");
        }

        return $invitation;
    }

    private function emetteur(): ?User
    {
        if (filled($this->option('by'))) {
            return User::where('email', mb_strtolower(trim($this->option('by'))))->first();
        }

        return User::where('is_admin', true)->orderBy('id')->first();
    }

    private function jours(): ?int
    {
        return filled($this->option('days')) ? (int) $this->option('days') : null;
    }
}
