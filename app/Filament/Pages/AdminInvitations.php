<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RequiresAdmin;
use App\Models\Invitation;
use App\Notifications\AccountInvitation;
use App\Services\InvitationService;
use App\Support\RegistrationGate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * ⚠️ PAS de `$slug` custom. Filament dérive le nom de route du slug : poser
 * `$slug = 'invitations'` donnerait `filament.admin.pages.invitations` alors que
 * `HelpContentRegistry` attendrait `admin-invitations`. C'est exactement le piège
 * `WhatsNew`/`nouveautes`, et il est MUET — repli silencieux sur `help._fallback`.
 */
class AdminInvitations extends Page
{
    use RequiresAdmin;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;
    protected static string|UnitEnum|null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Invitations';
    protected static ?string $title = 'Invitations';
    protected static ?int $navigationSort = 6;
    protected string $view = 'filament.pages.admin-invitations';

    /** Lien affiché UNE SEULE FOIS après création ou relance. */
    public ?string $dernierLien = null;

    public ?string $dernierEmail = null;

    public function getSubheading(): ?string
    {
        return 'Ouvrir un accès à une personne nommée, le relancer ou le révoquer.';
    }

    public function invitations()
    {
        return Invitation::query()->with(['invitedBy', 'user'])->orderByDesc('created_at')->get();
    }

    /** Message d'avertissement si le mode d'inscription rend les invitations inopérantes. */
    public function avertissementMode(): ?string
    {
        $mode = config('app.allow_registration', 'auto');

        if ($mode === RegistrationGate::MODE_INVITE) {
            return null;
        }

        if (! RegistrationGate::exposesRoute()) {
            return 'ALLOW_REGISTRATION vaut « ' . $mode . ' » : la page d\'inscription n\'existe pas '
                . 'sur cette instance, et les liens d\'invitation mèneront à une erreur. '
                . 'Passez la variable à « invite ».';
        }

        return 'ALLOW_REGISTRATION vaut « ' . $mode . ' » : l\'inscription est ouverte à tous, '
            . 'une invitation n\'est donc pas nécessaire pour créer un compte.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('inviter')
                ->label('Inviter quelqu\'un')
                ->icon('heroicon-o-paper-airplane')
                ->schema([
                    TextInput::make('email')->label('Adresse électronique')->email()->required()->maxLength(255),
                    TextInput::make('name')->label('Nom (facultatif)')->maxLength(255),
                    TextInput::make('days')->label('Validité, en jours')->numeric()->minValue(1)->maxValue(365)
                        ->default((int) config('invitations.ttl_days', 14)),
                    TextInput::make('note')->label('Note interne')->maxLength(255)
                        ->helperText('Jamais montrée à l\'invité.'),
                ])
                ->action(function (array $data): void {
                    try {
                        [$invitation, $plain] = app(InvitationService::class)->create(
                            $data['email'],
                            $data['name'] ?? null,
                            Auth::user(),
                            filled($data['days'] ?? null) ? (int) $data['days'] : null,
                            $data['note'] ?? null,
                        );
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    $envoye = app(InvitationService::class)->notify($invitation->refresh(), $plain);

                    // ⚠️ Le lien s'affiche même quand le courriel part : c'est le chemin qui
                    // sauve quand le SMTP est muet, et il n'existe qu'à cet instant — seule
                    // son empreinte est en base.
                    $this->dernierLien = AccountInvitation::urlFor($plain);
                    $this->dernierEmail = $invitation->email;

                    Notification::make()
                        ->title($envoye ? 'Invitation envoyée' : 'Invitation créée, courriel non parti')
                        ->body($envoye ? null : 'Transmettez le lien ci-dessous vous-même.')
                        ->{$envoye ? 'success' : 'warning'}()
                        ->send();
                }),
        ];
    }

    public function relancer(int $id): void
    {
        $invitation = Invitation::findOrFail($id);

        try {
            $plain = app(InvitationService::class)->resend($invitation);
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        app(InvitationService::class)->notify($invitation->refresh(), $plain);

        $this->dernierLien = AccountInvitation::urlFor($plain);
        $this->dernierEmail = $invitation->email;

        Notification::make()
            ->title('Invitation relancée')
            ->body('Le lien précédent ne fonctionne plus : relancer, c\'est remplacer.')
            ->success()
            ->send();
    }

    public function revoquer(int $id): void
    {
        $invitation = Invitation::findOrFail($id);

        try {
            app(InvitationService::class)->revoke($invitation);
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Invitation révoquée')
            ->body('Le lien ne fonctionne plus. Un compte déjà créé n\'est PAS suspendu pour autant.')
            ->success()
            ->send();
    }

    public function oublierLien(): void
    {
        $this->dernierLien = null;
        $this->dernierEmail = null;
    }
}
