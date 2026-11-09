<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\DocumentVault;
use App\Services\Vault\VaultException;
use App\Services\Vault\VaultKeyManager;
use App\Services\Vault\VaultMigrationReport;
use App\Services\Vault\VaultMigrator;
use App\Support\Vault as VaultState;
use App\Support\VaultSession;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Coffre-fort des justificatifs : activation, déverrouillage, changement de
 * phrase de passe, désactivation.
 *
 * Tout passe par des actions à modale plutôt que par un formulaire de page :
 * une phrase de passe ne doit jamais rester dans l'état Livewire d'une page
 * affichée en permanence.
 */
class Vault extends Page
{
    use NavigationAware;

    /** Fichiers traités par appel : au-delà, la requête HTTP devient trop longue. */
    public const BATCH_SIZE = 25;

    protected string $view = 'filament.pages.vault';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?string $navigationLabel = 'Coffre-fort';

    protected static ?string $title = 'Coffre-fort des justificatifs';

    protected static string | \UnitEnum | null $navigationGroup = 'Paramètres';

    protected static ?int $navigationSort = 20;

    public ?string $lastReport = null;

    public static function canAccess(): bool
    {
        return VaultState::canBeEnabledBy(auth()->user())
            || VaultState::isEnabledFor(auth()->user());
    }

    protected static function isHiddenInSimpleMode(): bool
    {
        return true;
    }

    public function getSubheading(): ?string
    {
        return 'Chiffrez vos justificatifs avec une phrase de passe que vous seul connaissez. '
            . 'Personne d\'autre — pas même l\'hébergeur de cette instance — ne peut alors les lire.';
    }

    // -------------------------------------------------------------------------
    // État consommé par la vue
    // -------------------------------------------------------------------------

    public function getVaultRecord(): ?DocumentVault
    {
        return VaultState::vaultOf(auth()->user());
    }

    public function isUnlocked(): bool
    {
        return VaultState::isUnlockedFor(auth()->user());
    }

    public function getPendingCount(): int
    {
        $vault = $this->getVaultRecord();

        if ($vault === null || ! $vault->isMigrating()) {
            return 0;
        }

        return app(VaultMigrator::class)->pendingCount(
            auth()->user(),
            $vault->status === DocumentVault::STATUS_ENCRYPTING
                ? VaultMigrator::DIRECTION_ENCRYPT
                : VaultMigrator::DIRECTION_DECRYPT,
        );
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            $this->enableAction(),
            $this->unlockAction(),
            $this->lockAction(),
            $this->changePassphraseAction(),
            $this->disableAction(),
        ];
    }

    private function enableAction(): Action
    {
        return Action::make('enable')
            ->label('Activer le coffre-fort')
            ->icon('heroicon-o-lock-closed')
            ->color('primary')
            ->visible(fn () => $this->getVaultRecord() === null && VaultState::canBeEnabledBy(auth()->user()))
            ->modalHeading('Activer le chiffrement de vos justificatifs')
            ->modalDescription(
                'Vos justificatifs seront chiffrés avec cette phrase de passe. '
                . 'Elle n\'est enregistrée nulle part : si vous la perdez, vos justificatifs sont '
                . 'définitivement illisibles, et nous n\'avons aucun moyen de vous les restituer.'
            )
            ->modalSubmitActionLabel('Activer et chiffrer mes fichiers')
            ->modalWidth('lg')
            ->form([
                TextInput::make('passphrase')
                    ->label('Phrase de passe')
                    ->helperText('Différente de votre mot de passe de connexion. Au moins '
                        . VaultKeyManager::MIN_PASSPHRASE_LENGTH . ' caractères.')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(VaultKeyManager::MIN_PASSPHRASE_LENGTH)
                    ->maxLength(255),
                TextInput::make('passphrase_confirmation')
                    ->label('Confirmez la phrase de passe')
                    ->password()
                    ->revealable()
                    ->required()
                    ->same('passphrase'),
                TextInput::make('acknowledgement')
                    ->label('Recopiez « JE COMPRENDS » pour confirmer')
                    ->helperText('Perdre cette phrase de passe, c\'est perdre vos justificatifs, sans recours.')
                    ->required()
                    ->rules(['in:JE COMPRENDS'])
                    ->validationMessages([
                        'in' => 'Recopiez exactement « JE COMPRENDS » (en majuscules).',
                    ]),
            ])
            ->action(function (array $data) {
                $user = auth()->user();

                if (! VaultState::canBeEnabledBy($user)) {
                    $this->fail('Le coffre-fort n\'est pas disponible sur ce compte.');

                    return;
                }

                try {
                    [$vault, $dek] = app(VaultKeyManager::class)->create($user, $data['passphrase']);
                } catch (VaultException $e) {
                    $this->fail($e->getMessage());

                    return;
                }

                $vault->update(['status' => DocumentVault::STATUS_ENCRYPTING]);

                // La clé vient d'être créée : elle est en mémoire, on peut chiffrer
                // le premier lot sans attendre le cookie (qui n'est lisible qu'à la
                // requête suivante).
                app(VaultSession::class)->unlock($user, $dek, $vault->key_version);

                $this->runBatch($vault, $dek, VaultMigrator::DIRECTION_ENCRYPT);

                $this->redirect(static::getUrl());
            });
    }

    private function unlockAction(): Action
    {
        return Action::make('unlock')
            ->label('Déverrouiller')
            ->icon('heroicon-o-key')
            ->color('primary')
            ->visible(fn () => $this->getVaultRecord() !== null && ! $this->isUnlocked())
            ->modalHeading('Déverrouiller le coffre-fort')
            ->modalDescription('Votre phrase de passe reste valable jusqu\'à la fermeture de votre navigateur.')
            ->modalSubmitActionLabel('Déverrouiller')
            ->modalWidth('md')
            ->form([
                TextInput::make('passphrase')
                    ->label('Phrase de passe')
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            ->action(function (array $data) {
                $user = auth()->user();

                try {
                    $dek = app(VaultKeyManager::class)->unlock($user, $data['passphrase']);
                } catch (VaultException $e) {
                    $this->fail($e->getMessage());

                    return;
                }

                app(VaultSession::class)->unlock($user, $dek, VaultState::keyVersionFor($user));

                Notification::make()
                    ->title('Coffre-fort déverrouillé')
                    ->success()
                    ->send();

                // Le cookie n'est lisible qu'à la requête suivante : on recharge.
                $this->redirect(static::getUrl());
            });
    }

    private function lockAction(): Action
    {
        return Action::make('lock')
            ->label('Verrouiller')
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->visible(fn () => $this->getVaultRecord() !== null && $this->isUnlocked())
            ->action(function () {
                app(VaultSession::class)->lock(auth()->user());

                Notification::make()
                    ->title('Coffre-fort verrouillé')
                    ->success()
                    ->send();

                $this->redirect(static::getUrl());
            });
    }

    private function changePassphraseAction(): Action
    {
        return Action::make('changePassphrase')
            ->label('Changer la phrase de passe')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn () => $this->getVaultRecord() !== null)
            ->modalHeading('Changer la phrase de passe')
            ->modalDescription('Vos fichiers ne sont pas réécrits : seule la clé est rescellée. '
                . 'L\'opération est immédiate, quel que soit le nombre de justificatifs.')
            ->modalSubmitActionLabel('Changer')
            ->modalWidth('md')
            ->form([
                TextInput::make('current_passphrase')
                    ->label('Phrase de passe actuelle')
                    ->password()
                    ->revealable()
                    ->required(),
                TextInput::make('new_passphrase')
                    ->label('Nouvelle phrase de passe')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(VaultKeyManager::MIN_PASSPHRASE_LENGTH)
                    ->maxLength(255),
                TextInput::make('new_passphrase_confirmation')
                    ->label('Confirmez la nouvelle phrase de passe')
                    ->password()
                    ->revealable()
                    ->required()
                    ->same('new_passphrase'),
            ])
            ->action(function (array $data) {
                try {
                    app(VaultKeyManager::class)->changePassphrase(
                        auth()->user(),
                        $data['current_passphrase'],
                        $data['new_passphrase'],
                    );
                } catch (VaultException $e) {
                    $this->fail($e->getMessage());

                    return;
                }

                Notification::make()
                    ->title('Phrase de passe changée')
                    ->body('Aucun fichier n\'a eu besoin d\'être réécrit.')
                    ->success()
                    ->send();

                $this->redirect(static::getUrl());
            });
    }

    private function disableAction(): Action
    {
        return Action::make('disable')
            ->label('Désactiver le coffre-fort')
            ->icon('heroicon-o-lock-open')
            ->color('danger')
            // ⚠️ Plus de condition « déverrouillé » ni de clé lue dans le cookie : celui-ci
            // ne porte que la DEK, qui n'ouvre pas les dépôts postérieurs à l'activation
            // (format 2, scellés pour le navigateur). La phrase de passe est demandée ici,
            // une fois, pour reconstituer la paire le temps de la requête.
            ->visible(fn () => $this->getVaultRecord() !== null)
            ->requiresConfirmation()
            ->modalHeading('Désactiver le chiffrement')
            ->modalDescription('Tous vos justificatifs seront déchiffrés et redeviendront lisibles '
                . 'par l\'hébergeur de cette instance, exactement comme avant l\'activation.')
            ->modalSubmitActionLabel('Déchiffrer et désactiver')
            ->form([
                TextInput::make('passphrase')
                    ->label('Phrase de passe')
                    ->helperText('Elle sert uniquement à déchiffrer vos fichiers, et n\'est conservée nulle part.')
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            ->action(function (array $data) {
                $user = auth()->user();
                $vault = $this->getVaultRecord();

                if ($vault === null) {
                    return;
                }

                try {
                    [$dek, $pair] = app(VaultKeyManager::class)->unlockForDecryption($user, $data['passphrase']);
                } catch (VaultException $e) {
                    $this->fail($e->getMessage());

                    return;
                }

                $vault->update(['status' => DocumentVault::STATUS_DECRYPTING]);

                // Tout en une fois : la paire n'existe que dans cette requête, un lot
                // suivant ne pourrait plus ouvrir les fichiers scellés.
                $this->runBatch($vault, $dek, VaultMigrator::DIRECTION_DECRYPT, $pair, batch: 0);

                if ($pair !== null) {
                    sodium_memzero($pair);
                }

                $this->redirect(static::getUrl());
            });
    }

    // -------------------------------------------------------------------------
    // Migration par lots — appelée depuis la vue tant qu'il reste des fichiers
    // -------------------------------------------------------------------------

    public function continueMigration(): void
    {
        $user = auth()->user();
        $vault = $this->getVaultRecord();
        $key = VaultState::keyFor($user);

        if ($vault === null || ! $vault->isMigrating()) {
            return;
        }

        if ($key === null) {
            $this->fail(VaultException::locked()->getMessage());

            return;
        }

        $this->runBatch(
            $vault,
            $key,
            $vault->status === DocumentVault::STATUS_ENCRYPTING
                ? VaultMigrator::DIRECTION_ENCRYPT
                : VaultMigrator::DIRECTION_DECRYPT,
        );
    }

    /**
     * Abandonne une migration bloquée. Les fichiers déjà traités le restent :
     * l'arborescence peut donc être mixte, ce que le bandeau d'état continue
     * d'afficher. Seule issue quand la phrase de passe a été perdue en cours de
     * route, `php artisan tinker` étant absent des images Docker.
     */
    public function abandonMigration(): void
    {
        $vault = $this->getVaultRecord();

        if ($vault === null || ! $vault->isMigrating()) {
            return;
        }

        $vault->update(['status' => DocumentVault::STATUS_ACTIVE]);

        Notification::make()
            ->title('Opération interrompue')
            ->body('Les fichiers non traités restent dans leur état actuel. Vous pouvez relancer l\'opération à tout moment.')
            ->warning()
            ->send();

        $this->redirect(static::getUrl());
    }

    private function runBatch(
        DocumentVault $vault,
        string $key,
        string $direction,
        ?string $sealedPair = null,
        int $batch = self::BATCH_SIZE,
    ): VaultMigrationReport {
        $report = app(VaultMigrator::class)->process(
            auth()->user(),
            $key,
            $direction,
            $batch,
            keyVersion: $vault->key_version,
            sealedPair: $sealedPair,
        );

        $this->lastReport = $report->summary();

        if ($report->missing > 0) {
            Notification::make()
                ->title('Justificatifs introuvables')
                ->body("{$report->missing} justificatif(s) référencé(s) dans vos données n'ont pas été retrouvés sur le disque : ils ont été ignorés.")
                ->warning()
                ->send();
        }

        if ($report->failed > 0) {
            Notification::make()
                ->title('Fichiers non traités')
                ->body("{$report->failed} fichier(s) n'ont pas pu être traités et sont restés intacts.")
                ->danger()
                ->send();
        }

        if (! $report->isComplete()) {
            Notification::make()
                ->title('Opération en cours')
                ->body("{$report->processed} fichier(s) traité(s), {$report->remaining} restant(s).")
                ->info()
                ->send();

            return $report;
        }

        if ($direction === VaultMigrator::DIRECTION_DECRYPT && $report->failed === 0) {
            app(VaultKeyManager::class)->destroy(auth()->user());
            app(VaultSession::class)->lock(auth()->user());

            Notification::make()
                ->title('Coffre-fort désactivé')
                ->body('Vos justificatifs ont été restitués à l\'identique.')
                ->success()
                ->send();

            return $report;
        }

        $vault->update(['status' => DocumentVault::STATUS_ACTIVE]);

        if ($direction === VaultMigrator::DIRECTION_ENCRYPT) {
            Notification::make()
                ->title('Coffre-fort actif')
                ->body('Vos justificatifs sont chiffrés. Conservez précieusement votre phrase de passe.')
                ->success()
                ->send();
        }

        return $report;
    }

    private function fail(string $message): void
    {
        Notification::make()
            ->title('Opération impossible')
            ->body($message)
            ->danger()
            ->send();
    }
}
