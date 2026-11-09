<?php

namespace App\Filament\Auth;

use App\Models\Invitation;
use App\Services\InvitationService;
use App\Support\RegistrationGate;
use Filament\Auth\Pages\Register;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Page d'inscription, en mode libre comme en mode invitation.
 *
 * ⚠️ UNE SEULE classe pour les quatre modes d'`ALLOW_REGISTRATION`. En mode « auto », « true »
 * ou pendant l'amorçage d'une instance neuve, elle se comporte exactement comme la page
 * d'origine — c'est ce qui garde `FilamentPagesTest` et `RegistrationFlagTest` intacts.
 */
class RegisterFromInvitation extends Register
{
    /**
     * ⚠️ Propriété du trait `CanUseDatabaseTransactions`. Sans elle,
     * `hasDatabaseTransactions()` retombe sur le réglage du PANEL (false ici) et
     * `handleRegistration()` ne serait pas transactionnel — on pourrait consommer
     * l'invitation sans créer le compte.
     */
    protected ?bool $hasDatabaseTransactions = true;

    public ?int $invitationId = null;

    public function mount(): void
    {
        parent::mount();

        if (! RegistrationGate::requiresInvitation()) {
            return;
        }

        $invitation = $this->invitationFromSession();

        if ($invitation === null) {
            Notification::make()
                ->title('Inscription sur invitation')
                ->body('Cette instance n\'accepte que les inscriptions sur invitation. Ouvrez le lien reçu par courriel.')
                ->warning()
                ->persistent()
                ->send();

            $this->redirect(Filament::getLoginUrl());

            return;
        }

        $this->invitationId = $invitation->id;

        $this->form->fill([
            'name' => $invitation->name ?? '',
            'email' => $invitation->email,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        if (! RegistrationGate::requiresInvitation()) {
            return $schema;
        }

        // ⚠️ L'adresse est ÉPINGLÉE et non déshydratée : elle ne vient jamais du formulaire.
        // `handleRegistration()` la reprend sur la ligne d'invitation, si bien qu'une
        // invitation émise pour A ne peut pas créer un compte pour B — même si le navigateur
        // envoie autre chose. C'est aussi ce qui fait disparaître toute surface
        // d'énumération d'adresses : il n'y a plus de champ à sonder.
        return $schema->components([
            $this->getNameFormComponent(),
            TextInput::make('email')
                ->label(__('filament-panels::auth/pages/register.form.email.label'))
                ->disabled()
                ->dehydrated(false),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        if (! RegistrationGate::requiresInvitation()) {
            return parent::handleRegistration($data);
        }

        $invitation = $this->invitationFromSession();

        if ($invitation === null || $invitation->id !== $this->invitationId) {
            throw ValidationException::withMessages([
                'data.password' => 'Votre session d\'inscription a expiré, ou l\'invitation a été relancée. '
                    . 'Rouvrez le lien reçu par courriel : il n\'a pas encore été utilisé.',
            ]);
        }

        try {
            $user = app(InvitationService::class)->claim($invitation, $data);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['data.password' => $e->getMessage()]);
        }

        session()->forget('invitation');

        return $user;
    }

    /**
     * Relit l'invitation depuis la session en revérifiant l'empreinte du jeton.
     *
     * ⚠️ On ne fait pas confiance au seul identifiant : une RELANCE réécrit `token_hash`, donc
     * une inscription laissée ouverte dans un onglet devient caduque — gratuitement, et c'est
     * le comportement voulu (relancer, c'est remplacer).
     */
    private function invitationFromSession(): ?Invitation
    {
        $jeton = session('invitation');

        if (! is_array($jeton) || blank($jeton['id'] ?? null) || blank($jeton['hash'] ?? null)) {
            return null;
        }

        $invitation = Invitation::find($jeton['id']);

        if ($invitation === null
            || ! hash_equals($invitation->token_hash, (string) $jeton['hash'])
            || ! $invitation->isPending()) {
            return null;
        }

        return $invitation;
    }
}
